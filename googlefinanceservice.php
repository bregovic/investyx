<?php

/**
 * GoogleFinanceService
 *
 * - načítá aktuální cenu z Google Finance (scraping)
 * - ukládá do tabulky broker_live_quotes
 * - vrací asociativní pole s daty
 *
 * Použití:
 *   require_once __DIR__ . '/googlefinanceservice.php';
 *   $service = new GoogleFinanceService($pdo, 0);
 *   $data = $service->getQuote('AAPL', true);
 */
class GoogleFinanceService
{
    /** @var PDO */
    private $pdo;

    /** @var int TTL v sekundách; 0 = jen dnešní záznam */
    private $ttlSeconds;

    public function __construct(PDO $pdo, int $ttlSeconds = 0)
    {
        $this->pdo = $pdo;
        $this->ttlSeconds = $ttlSeconds;
    }

    /**
     * Vrátí data pro ticker.
     * @param string $ticker
     * @param bool   $forceFresh  true = vždy natáhne z webu a uloží do DB
     * @return array|null
     */
    public function getQuote(string $ticker, bool $forceFresh = false, ?string $targetCurrency = null): ?array
    {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '') {
            throw new InvalidArgumentException('Ticker is empty');
        }

        // 1. Check mapping for price_source
        // We do this first to avoid fetching if source is MANUAL
        $stmt = $this->pdo->prepare("SELECT price_source, company_name FROM broker_ticker_mapping WHERE ticker = ? LIMIT 1");
        $stmt->execute([$ticker]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($mapping && isset($mapping['price_source']) && $mapping['price_source'] === 'manual') {
            // If manual, we DO NOT fetch from Google. 
            // We only check cache (which might hold the manually entered price)
            return $this->getCachedQuote($ticker);
        }

        if (!$forceFresh) {
            $cached = $this->getCachedQuote($ticker);
            if ($cached !== null) {
                // Validate cached data against mapping too, just in case
                if ($this->validateAgainstMapping($ticker, $cached['company_name'])) {
                    return $cached;
                }
            }
        }

        $data = $this->fetchFromGoogleFinance($ticker, $targetCurrency);
        if ($data === null) {
            return null;
        }

        // Validate before saving
        if (!$this->validateAgainstMapping($ticker, $data['company_name'])) {
            // Log warning or just return null
            return null;
        }

        $this->saveQuote($ticker, $data);

        return $data;
    }

    /**
     * Validate if the fetched company name matches the mapping (if exists)
     */
    private function validateAgainstMapping(string $ticker, ?string $fetchedName): bool
    {
        if (empty($fetchedName)) return true; // Cannot validate if no name

        // Get mapping
        $stmt = $this->pdo->prepare("SELECT company_name FROM broker_ticker_mapping WHERE ticker = ? LIMIT 1");
        $stmt->execute([$ticker]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mapping || empty($mapping['company_name'])) {
            return true; // No mapping, assume it's correct
        }

        $mappedName = $mapping['company_name'];
        
        // Normalize names for comparison
        $n1 = mb_strtolower(trim($mappedName));
        $n2 = mb_strtolower(trim($fetchedName));
        
        // Remove common suffixes
        $suffixes = [' inc', ' corp', ' ag', ' se', ' plc', ' ltd', ' s.a.', ' corporation', ' incorporated', ' limited', ' group', ' holdings'];
        foreach ($suffixes as $s) {
            $n1 = str_replace($s, '', $n1);
            $n2 = str_replace($s, '', $n2);
        }
        
        // 1. Exact match (after cleanup)
        if ($n1 === $n2) return true;
        
        // 2. Containment
        if (strpos($n1, $n2) !== false || strpos($n2, $n1) !== false) return true;
        
        // 3. Similarity
        $sim = 0;
        similar_text($n1, $n2, $sim);
        
        // If similarity is low, reject
        if ($sim < 40) {
            return false;
        }

        return true;
    }

    /**
     * Načte z DB záznam v rámci TTL (nebo dnešní, pokud je TTL=0).
     */
    private function getCachedQuote(string $ticker): ?array
    {
        if ($this->ttlSeconds === 0) {
            $sql = "
                SELECT id          AS ticker,
                       current_price,
                       change_percent,
                       company_name,
                       exchange,
                       currency,
                       last_fetched
                FROM broker_live_quotes
                WHERE id = :ticker
                  AND status = 'active'
                  AND current_price IS NOT NULL
                  AND DATE(last_fetched) = CURRENT_DATE()
                ORDER BY last_fetched DESC
                LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
        } else {
            $sql = "
                SELECT id          AS ticker,
                       current_price,
                       change_percent,
                       company_name,
                       exchange,
                       currency,
                       last_fetched
                FROM broker_live_quotes
                WHERE id = :ticker
                  AND status = 'active'
                  AND current_price IS NOT NULL
                  AND last_fetched >= (NOW() - INTERVAL :ttl SECOND)
                ORDER BY last_fetched DESC
                LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':ttl', $this->ttlSeconds, PDO::PARAM_INT);
        }

        $stmt->bindValue(':ticker', $ticker, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'ticker'         => $row['ticker'],
            'current_price'  => (float)$row['current_price'],
            'change_percent' => $row['change_percent'] !== null ? (float)$row['change_percent'] : null,
            'company_name'   => $row['company_name'],
            'exchange'       => $row['exchange'],
            'currency'       => $row['currency'],
            'last_fetched'   => $row['last_fetched'],
        ];
    }

    /**
     * Uloží/aktualizuje záznam v broker_live_quotes.
     */
    private function saveQuote(string $ticker, array $data): void
    {
        $sql = "
            INSERT INTO broker_live_quotes
                (id, source, current_price, change_percent, company_name, exchange, currency, last_fetched, status)
            VALUES
                (:ticker, 'google_finance', :price, :change, :company, :exchange, :currency, NOW(), 'active')
            ON DUPLICATE KEY UPDATE
                current_price  = VALUES(current_price),
                change_percent = VALUES(change_percent),
                company_name   = VALUES(company_name),
                exchange       = VALUES(exchange),
                currency       = VALUES(currency),
                last_fetched   = NOW(),
                status         = 'active'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':ticker'   => $ticker,
            ':price'    => $data['current_price'],
            ':change'   => $data['change_percent'] ?? 0,
            ':company'  => $data['company_name'] ?? $ticker,
            ':exchange' => $data['exchange'] ?? 'UNKNOWN',
            ':currency' => $data['currency'] ?? 'USD',
        ]);
    }

    /**
     * Stáhne data z Google Finance (stocks) nebo CoinGecko (crypto).
     */
    private function fetchFromGoogleFinance(string $ticker, ?string $targetCurrency = null): ?array
    {
        // Detect crypto tickers and use CoinGecko API
        $cryptoTickers = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'ADA' => 'cardano',
            'DOT' => 'polkadot',
            'SOL' => 'solana',
            'MATIC' => 'matic-network',
            'AVAX' => 'avalanche-2',
            'LINK' => 'chainlink',
            'UNI' => 'uniswap',
            'ATOM' => 'cosmos',
            'XRP' => 'ripple',
            'LTC' => 'litecoin',
            'BCH' => 'bitcoin-cash',
            'DOGE' => 'dogecoin',
            'SHIB' => 'shiba-inu'
        ];

        $isCrypto = isset($cryptoTickers[$ticker]);

        // For crypto, use CoinGecko API
        if ($isCrypto) {
            $coinGeckoId = $cryptoTickers[$ticker];
            $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . urlencode($coinGeckoId) . '&vs_currencies=usd';
            
            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 5,
                    'header'  => "User-Agent: Mozilla/5.0 (PortfolioTracker)\r\nAccept: application/json\r\n",
                ]
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response !== false && $response !== '') {
                $apiData = json_decode($response, true);
                if (isset($apiData[$coinGeckoId]['usd'])) {
                    return [
                        'ticker'         => $ticker,
                        'current_price'  => (float)$apiData[$coinGeckoId]['usd'],
                        'change_percent' => null, // CoinGecko simple API doesn't provide this
                        'company_name'   => $ticker,
                        'exchange'       => 'CRYPTO',
                        'currency'       => 'USD',
                    ];
                }
            }
            
            return null;
        }

        // For stocks, use Google Finance (existing logic)
        // For stocks, use Google Finance (existing logic)
        $exchanges = ['NASDAQ', 'NYSE', 'NYSEARCA'];
        
        // Prioritize European exchanges if EUR is requested
        if ($targetCurrency === 'EUR') {
            array_unshift($exchanges, 'FRA', 'ETR', 'AMS', 'BIT');
        }
        
        // Add specific exchanges for ETFs often traded in Europe (London, Xetra, etc.)
        // ZPRV, CNDX, VWRA, CSPX, IWVL often trade on LSE (LON) or Xetra (FRA/ETR)
        $etfTickers = ['ZPRV', 'CNDX', 'VWRA', 'CSPX', 'IWVL', 'EQQQ', 'EUNL', 'IS3N', 'SXR8'];
        if (in_array($ticker, $etfTickers)) {
            // Priority for these ETFs: Xetra (EUR) -> London (USD/GBP) -> Amsterdam
            array_unshift($exchanges, 'LON', 'AMS', 'SWX', 'FRA'); 
        }
        
        // Prioritize European exchanges if EUR is requested
        if ($targetCurrency === 'EUR') {
            array_unshift($exchanges, 'FRA', 'ETR');
        }
        
        $candidates = [];

        foreach ($exchanges as $ex) {
            $candidates[] = $ticker . ':' . $ex;
        }
        $candidates[] = $ticker;

        $data = [
            'ticker'         => $ticker,
            'current_price'  => null,
            'change_percent' => null,
            'company_name'   => null,
            'exchange'       => null,
            'currency'       => 'USD',
        ];

        foreach ($candidates as $code) {
            $url = 'https://www.google.com/finance/quote/' . urlencode($code) . '?hl=en';

            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 10,
                    'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                                "Accept: text/html,application/xhtml+xml\r\n" .
                                "Accept-Language: en-US,en;q=0.9\r\n",
                ],
            ]);

            $html = @file_get_contents($url, false, $context);
            if ($html === false || $html === '') {
                continue;
            }

            // Cena
            if (preg_match('~<div[^>]+class="[^"]*YMlKec[^"]*fxKbKc[^"]*"[^>]*>(.*?)</div>~s', $html, $m)) {
                $text = strip_tags(htmlspecialchars_decode($m[1], ENT_QUOTES | ENT_HTML5));
                $text = str_replace(["\xc2\xa0", ' ', ','], '', $text);
                $text = preg_replace('/[^\d\.\-]/', '', $text);

                if ($text !== '' && is_numeric($text)) {
                    $price = (float)$text;
                    if ($price > 0) {
                        $data['current_price'] = $price;

                        // % změna – vezmeme číslo v závorkách "(...%)"
                        if (preg_match('/\(([+\-−]?[0-9,.]+)%\)\s*<\/div>/u', $html, $matches)) {
                            $data['change_percent'] = (float)str_replace(',', '', $matches[1]);
                        }

                        // Jméno firmy
                        if (preg_match('/<div[^>]*class="[^"]*zzDege[^"]*"[^>]*>([^<]+)<\/div>/i', $html, $matches)) {
                            $data['company_name'] = trim($matches[1]);
                        }

                        // Dividend yield
                        // Hledáme text "Dividend yield" a poté následuje procento
                        if (preg_match('/Dividend yield.*?<div[^>]*>([0-9\.,]+)%<\/div>/is', $html, $matches)) {
                            $data['dividend_yield'] = (float)str_replace(',', '.', $matches[1]);
                        } else {
                            $data['dividend_yield'] = null;
                        }

                        // Burza z kódu
                        if (preg_match('/([A-Z]+):/', $code, $matches)) {
                            $data['exchange'] = $matches[1];
                        }

                        // Currency detection from the page
                        if (preg_match('/<div[^>]*class="[^"]*C5N78d[^"]*"[^>]*>[^<]*?([A-Z]{3})[^<]*?<\/div>/', $html, $matches)) {
                             $data['currency'] = $matches[1];
                        } elseif (preg_match('/Currency in ([A-Z]{3})/', $html, $matches)) {
                             $data['currency'] = $matches[1];
                        } elseif ($data['exchange'] === 'FRA' || $data['exchange'] === 'ETR') {
                             $data['currency'] = 'EUR';
                        } elseif ($data['exchange'] === 'LON') {
                             $data['currency'] = 'GBP';
                        } elseif ($data['exchange'] === 'TSE') {
                             $data['currency'] = 'JPY';
                        }
                        
                        break; // máme data, konec
                    }
                }
            }
        }

        return $data['current_price'] ? $data : null;
    }
    
    // Uloží/aktualizuje záznam
    private function saveQuote(string $ticker, array $data): void
    {
        // Add dividend_yield column if not passed (though we update map below)
        $div = $data['dividend_yield'] ?? null;
        
        $sql = "
            INSERT INTO broker_live_quotes
                (id, source, current_price, change_percent, dividend_yield, company_name, exchange, currency, last_fetched, status)
            VALUES
                (:ticker, 'google_finance', :price, :change, :div, :company, :exchange, :currency, NOW(), 'active')
            ON DUPLICATE KEY UPDATE
                current_price  = VALUES(current_price),
                change_percent = VALUES(change_percent),
                dividend_yield = VALUES(dividend_yield),
                company_name   = VALUES(company_name),
                exchange       = VALUES(exchange),
                currency       = VALUES(currency),
                last_fetched   = NOW(),
                status         = 'active'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':ticker'   => $ticker,
            ':price'    => $data['current_price'],
            ':change'   => $data['change_percent'] ?? 0,
            ':div'      => $div,
            ':company'  => $data['company_name'] ?? $ticker,
            ':exchange' => $data['exchange'] ?? 'UNKNOWN',
            ':currency' => $data['currency'] ?? 'USD',
        ]);
    }
}
