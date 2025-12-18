import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip as ChartTooltip, ResponsiveContainer } from 'recharts';
import { useEffect, useState } from 'react';
import axios from 'axios';
import {
    TableCellLayout,
    type TableColumnDefinition,
    createTableColumn,
    Toolbar,
    ToolbarButton,
    ToolbarDivider,
    makeStyles,
    tokens,
    Input,
    Spinner,
    Badge,
    Dialog,
    DialogTrigger,
    DialogSurface,
    DialogTitle,
    DialogBody,
    DialogActions,
    DialogContent,
    Button,
    Text,
    Switch
} from '@fluentui/react-components';
import {
    Add24Regular,
    ArrowClockwise24Regular,
    ArrowDownload24Regular,
    Flash24Regular,
    Line24Regular
} from '@fluentui/react-icons';
import { SmartDataGrid } from '../components/SmartDataGrid';
import { PageLayout, PageHeader, PageContent } from '../components/PageLayout';

const useStyles = makeStyles({
    gridCard: {
        padding: '0',
        backgroundColor: tokens.colorNeutralBackground1,
        border: `1px solid ${tokens.colorNeutralStroke1}`,
        borderRadius: '8px',
        overflow: 'auto', // Allow scroll
        display: 'flex',
        flexDirection: 'column'
    },
    smallText: { fontSize: '11px', lineHeight: '14px' },
    pos: { color: tokens.colorPaletteGreenForeground1 },
    neg: { color: tokens.colorPaletteRedForeground1 }
});

interface MarketItem {
    ticker: string;
    company_name: string;
    exchange: string;
    currency: string;
    current_price: number;
    change_percent: number;
    change_absolute: number;
    asset_type: string;
    high_52w?: number;
    low_52w?: number;
    ema_212?: number;
    last_fetched?: string;
    is_watched?: number;
}

// Chart Component
const ChartModal = ({ open, ticker, currency, companyName, onClose }: { open: boolean, ticker: string, currency: string, companyName: string, onClose: () => void }) => {
    const [allData, setAllData] = useState<{ date: string, price: number }[]>([]);
    const [loading, setLoading] = useState(false);
    const [period, setPeriod] = useState('1Y');

    // Helper to get API URL inside component
    const isDev = import.meta.env.DEV;
    const getApiUrl = (endpoint: string) => isDev
        ? `http://localhost/Webhry/hollyhop/broker/investyx/${endpoint}`
        : `/investyx/${endpoint}`;

    const handleRefreshData = async () => {
        setLoading(true);
        try {
            await axios.post(getApiUrl('ajax-fetch-history.php'), { ticker, action: 'fetch' });

            const res = await axios.get(getApiUrl(`ajax-get-chart-data.php?ticker=${ticker}`));
            if (res.data && res.data.success) {
                const chartData = res.data.labels.map((date: string, i: number) => ({
                    date,
                    price: res.data.data[i]
                }));
                setAllData(chartData);
            }
        } catch (e: any) {
            alert('Chyba aktualizace: ' + e.message);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open && ticker) {
            setLoading(true);
            axios.get(getApiUrl(`ajax-get-chart-data.php?ticker=${ticker}`))
                .then(res => {
                    if (res.data && res.data.success) {
                        const chartData = res.data.labels.map((date: string, i: number) => ({
                            date,
                            price: res.data.data[i]
                        }));
                        setAllData(chartData);
                    }
                })
                .catch(err => console.error(err))
                .finally(() => setLoading(false));
        }
    }, [open, ticker]);

    // Filter Data
    const data = allData.filter(item => {
        if (period === 'MAX') return true;
        const itemDate = new Date(item.date);
        const cutoff = new Date();
        if (period === '1M') cutoff.setMonth(cutoff.getMonth() - 1);
        else if (period === '3M') cutoff.setMonth(cutoff.getMonth() - 3);
        else if (period === '6M') cutoff.setMonth(cutoff.getMonth() - 6);
        else if (period === '1Y') cutoff.setFullYear(cutoff.getFullYear() - 1);
        else if (period === '2Y') cutoff.setFullYear(cutoff.getFullYear() - 2);
        else if (period === '5Y') cutoff.setFullYear(cutoff.getFullYear() - 5);
        return itemDate >= cutoff;
    });

    const periods = ['1M', '3M', '6M', '1Y', '2Y', '5Y', 'MAX'];

    return (
        <Dialog open={open} onOpenChange={(_, data) => !data.open && onClose()}>
            <DialogSurface aria-label="Chart" style={{ maxWidth: '1000px', width: '90%', height: '80vh' }}>
                <DialogBody style={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
                    <DialogTitle>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '10px' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                <span style={{ fontSize: '20px', fontWeight: 600 }}>📈 {ticker}</span>
                                <span style={{ color: '#666', fontWeight: 400 }}>- {companyName}</span>
                            </div>
                            <div style={{ display: 'flex', gap: '5px', alignItems: 'center' }}>
                                <div style={{ display: 'flex', gap: '2px', marginRight: '10px' }}>
                                    {periods.map(p => (
                                        <Button
                                            key={p}
                                            size="small"
                                            appearance={period === p ? 'primary' : 'subtle'}
                                            onClick={() => setPeriod(p)}
                                            style={{ minWidth: '40px' }}
                                        >
                                            {p}
                                        </Button>
                                    ))}
                                </div>
                                <Button
                                    size="small"
                                    appearance="primary"
                                    icon={<ArrowDownload24Regular fontSize={16} />}
                                    onClick={handleRefreshData}
                                    style={{ backgroundColor: '#0f172a' }}
                                >
                                    Stáhnout data
                                </Button>
                            </div>
                        </div>
                    </DialogTitle>
                    <DialogContent style={{ flex: 1, minHeight: '300px', overflow: 'hidden', padding: '10px 0' }}>
                        {loading ? <Spinner label="Pracuji..." /> : (
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={data} margin={{ top: 10, right: 30, left: 20, bottom: 5 }}>
                                    <CartesianGrid strokeDasharray="3 3" vertical={true} horizontal={true} stroke="#e5e7eb" />
                                    <XAxis
                                        dataKey="date"
                                        tickFormatter={(val) => new Date(val).toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric' })}
                                        minTickGap={50}
                                        stroke="#9ca3af"
                                        tick={{ fontSize: 11 }}
                                    />
                                    <YAxis
                                        domain={['auto', 'auto']}
                                        tickFormatter={(val) => val.toLocaleString()}
                                        stroke="#9ca3af"
                                        tick={{ fontSize: 11 }}
                                    />
                                    <ChartTooltip
                                        labelFormatter={(val) => new Date(val).toLocaleDateString()}
                                        formatter={(val: any) => [Number(val).toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' ' + currency, 'Cena']}
                                        contentStyle={{ borderRadius: '4px', border: '1px solid #e5e7eb', boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)' }}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="price"
                                        stroke="#10b981"
                                        strokeWidth={2}
                                        dot={false}
                                        activeDot={{ r: 6, fill: '#10b981' }}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        )}
                        {data.length === 0 && !loading && <Text>Žádná data pro zvolené období.</Text>}
                    </DialogContent>
                    <DialogActions>
                        <Button onClick={onClose}>Zavřít</Button>
                    </DialogActions>
                </DialogBody>
            </DialogSurface>
        </Dialog>
    );
};

import { useTranslation } from '../context/TranslationContext'; // Add Import

// ... inside MarketPage ...

const MarketPage = () => {
    const styles = useStyles();
    const { t } = useTranslation(); // Init Hook

    const [items, setItems] = useState<MarketItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [filterText] = useState('');
    const [showWatchedOnly, setShowWatchedOnly] = useState(false);

    // Add Ticker State
    const [isAddOpen, setAddOpen] = useState(false);
    const [newTicker, setNewTicker] = useState('');
    const [adding, setAdding] = useState(false);

    // Chart State
    const [chartTicker, setChartTicker] = useState<string | null>(null);

    // History Update State
    const [historyUpdateOpen, setHistoryUpdateOpen] = useState(false);
    const [historyProgress, setHistoryProgress] = useState({ current: 0, total: 0, lastTicker: '' });
    const [historyLog, setHistoryLog] = useState<string[]>([]);

    const [isUpdatingHistory, setIsUpdatingHistory] = useState(false);
    const [loadingText, setLoadingText] = useState('');

    const chunkArray = (arr: any[], size: number) => {
        return Array.from({ length: Math.ceil(arr.length / size) }, (_, i) =>
            arr.slice(i * size, i * size + size)
        );
    };

    // Functions
    const isDev = import.meta.env.DEV;
    const getApiUrl = (endpoint: string) => isDev
        ? `http://localhost/Webhry/hollyhop/broker/investyx/${endpoint}`
        : `/investyx/${endpoint}`;

    const toggleWatch = async (ticker: string) => {
        // Optimistic
        setItems(prev => prev.map(i => i.ticker === ticker ? { ...i, is_watched: i.is_watched ? 0 : 1 } : i));
        try {
            await axios.post(getApiUrl('ajax-toggle-watch.php'), { ticker });
        } catch (e) {
            console.error(e);
            // Revert
            setItems(prev => prev.map(i => i.ticker === ticker ? { ...i, is_watched: i.is_watched ? 0 : 1 } : i));
        }
    };

    const fetchItems = () => {
        setLoading(true);
        axios.get(getApiUrl('api-market-data.php'))
            .then(res => {
                if (res.data && res.data.error) {
                    alert('Chyba načítání dat: ' + res.data.error);
                }
                if (res.data && res.data.data) {
                    setItems(res.data.data);
                }
            })
            .catch(err => {
                console.error("API Error", err);
                alert('Chyba komunikace s API: ' + err.message);
            })
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        fetchItems();
    }, []);

    const filteredItems = items.filter(item => {
        // Explicit comparison - is_watched can be 1, 0, "1", "0" from API
        if (showWatchedOnly && Number(item.is_watched) !== 1) return false;

        if (!filterText) return true;
        const search = filterText.toLowerCase();
        return (
            item.ticker.toLowerCase().includes(search) ||
            item.company_name.toLowerCase().includes(search) ||
            (item.exchange && item.exchange.toLowerCase().includes(search))
        );
    });

    const handleUpdatePrices = async () => {
        if (!confirm('Chcete aktualizovat ceny všech tickerů? (Může to chvíli trvat)')) return;
        setLoading(true);
        setLoadingText('Příprava...');

        try {
            const targets = items.map(i => ({ ticker: i.ticker, currency: i.currency }));
            const batches = chunkArray(targets, 5);
            let processed = 0;

            for (const batch of batches) {
                setLoadingText(`Aktualizuji ${processed} / ${targets.length}...`);
                await axios.post(getApiUrl('ajax-live-prices.php'), { tickers: batch });
                processed += batch.length;
                await new Promise(r => setTimeout(r, 100));
            }

            fetchItems();
            alert('Ceny aktualizovány.');
        } catch (e: any) {
            alert('Chyba: ' + e.message);
        } finally {
            setLoading(false);
            setLoadingText('');
        }
    };

    const handleAddTicker = async () => {
        if (!newTicker) return;
        setAdding(true);
        try {
            const res = await axios.post(getApiUrl('ajax_import_ticker.php'), { ticker: newTicker });
            if (res.data && res.data.success) {
                setAddOpen(false);
                setNewTicker('');
                fetchItems();
                alert('Ticker přidán!');
            } else {
                alert('Chyba: ' + (res.data?.message || 'Unknown error'));
            }
        } catch (e: any) {
            alert('Chyba komunikace: ' + e.message);
        } finally {
            setAdding(false);
        }
    };

    const handleUpdateHistory = async () => {
        setHistoryUpdateOpen(true);
        setIsUpdatingHistory(true);
        setHistoryLog([]);
        setHistoryProgress({ current: 0, total: 0, lastTicker: 'Inicializace...' });

        try {
            setHistoryLog(prev => [...prev, 'Získávám seznam tickerů...']);
            const listRes = await axios.post(getApiUrl('ajax-fetch-history.php'), { action: 'list' });

            if (listRes.data && listRes.data.success && Array.isArray(listRes.data.tickers)) {
                const tickers: string[] = listRes.data.tickers;
                const total = tickers.length;
                setHistoryProgress({ current: 0, total, lastTicker: '' });
                setHistoryLog(prev => [...prev, `Nalezeno ${total} tickerů k aktualizaci.`]);

                let successCount = 0;
                let failCount = 0;

                for (let i = 0; i < total; i++) {
                    const ticker = tickers[i];
                    setHistoryProgress({ current: i + 1, total, lastTicker: ticker });

                    try {
                        const res = await axios.post(getApiUrl('ajax-fetch-history.php'), { ticker, action: 'fetch' });
                        if (res.data.success) {
                            successCount++;
                        } else {
                            failCount++;
                            setHistoryLog(prev => [...prev, `CHYBA ${ticker}: ${res.data.message}`]);
                        }
                    } catch (e: any) {
                        failCount++;
                        setHistoryLog(prev => [...prev, `CHYBA ${ticker}: ${e.message}`]);
                    }
                    await new Promise(r => setTimeout(r, 100));
                }

                setHistoryLog(prev => [...prev, `Hotovo. Úspěšně: ${successCount}, Chyby: ${failCount}`]);
                fetchItems();
            } else {
                setHistoryLog(prev => [...prev, 'Chyba: Nepodařilo se získat seznam tickerů.']);
            }

        } catch (e: any) {
            setHistoryLog(prev => [...prev, `Kritická chyba: ${e.message}`]);
        } finally {
            setIsUpdatingHistory(false);
        }
    };

    // Columns
    const columns: TableColumnDefinition<MarketItem>[] = [
        createTableColumn<MarketItem>({
            columnId: 'ticker',
            compare: (a, b) => a.ticker.localeCompare(b.ticker),
            renderHeaderCell: () => t('col_ticker'),
            renderCell: (item) => (
                <TableCellLayout media={
                    <span
                        style={{
                            fontSize: '16px',
                            color: item.is_watched ? '#f5b942' : tokens.colorNeutralForeground3,
                            cursor: 'pointer'
                        }}
                        onClick={(e) => { e.stopPropagation(); toggleWatch(item.ticker); }}
                    >
                        {item.is_watched ? '★' : '☆'}
                    </span>
                }>
                    <strong>{item.ticker}</strong>
                </TableCellLayout>
            ),
        }),
        createTableColumn<MarketItem>({
            columnId: 'company_name',
            compare: (a, b) => a.company_name.localeCompare(b.company_name),
            renderHeaderCell: () => t('col_company'),
            renderCell: (item) => <Text size={200} block truncate wrap={false}>{item.company_name}</Text>,
        }),
        createTableColumn<MarketItem>({
            columnId: 'exchange',
            renderHeaderCell: () => t('col_exchange'),
            renderCell: (item) => <Badge appearance="tint" size="small">{item.exchange}</Badge>,
        }),
        createTableColumn<MarketItem>({
            columnId: 'price',
            compare: (a, b) => a.current_price - b.current_price,
            renderHeaderCell: () => t('col_price'),
            renderCell: (item) => (
                <div>
                    <strong>{Number(item.current_price).toLocaleString()}</strong> <small style={{ color: tokens.colorNeutralForeground3 }}>{item.currency}</small>
                </div>
            ),
        }),
        createTableColumn<MarketItem>({
            columnId: 'change',
            compare: (a, b) => a.change_absolute - b.change_absolute,
            renderHeaderCell: () => t('col_change'),
            renderCell: (item) => {
                const val = Number(item.change_absolute);
                return <span className={val >= 0 ? styles.pos : styles.neg} style={{ fontWeight: 600 }}>{val > 0 ? '+' : ''}{val.toFixed(4)}</span>;
            },
        }),
        createTableColumn<MarketItem>({
            columnId: 'change_pct',
            compare: (a, b) => a.change_percent - b.change_percent,
            renderHeaderCell: () => t('col_change_pct'),
            renderCell: (item) => {
                const val = Number(item.change_percent);
                return <span className={val >= 0 ? styles.pos : styles.neg} style={{ fontWeight: 600 }}>{val > 0 ? '+' : ''}{val.toFixed(2)}%</span>;
            },
        }),
        createTableColumn<MarketItem>({
            columnId: 'range',
            compare: (a, b) => {
                const rA = a.high_52w ? (a.current_price / a.high_52w) : 0;
                const rB = b.high_52w ? (b.current_price / b.high_52w) : 0;
                return rA - rB;
            },
            renderHeaderCell: () => t('col_range'),
            renderCell: (item) => {
                if (!item.high_52w || !item.low_52w || item.high_52w === 0) return <span className={styles.smallText}>-</span>;
                const max = Number(item.high_52w);
                const min = Number(item.low_52w);
                const cur = Number(item.current_price);
                const fromMax = ((cur - max) / max) * 100;
                const fromMin = min > 0 ? ((cur - min) / min) * 100 : 0;

                return (
                    <div style={{ display: 'flex', flexDirection: 'column', fontSize: '11px' }}>
                        <span className={styles.neg}>{fromMax.toFixed(1)}% ({t('from_max')})</span>
                        <span className={styles.pos}>+{fromMin.toFixed(1)}% ({t('from_min')})</span>
                    </div>
                );
            }
        }),
        createTableColumn<MarketItem>({
            columnId: 'trend',
            compare: (a, b) => {
                const dA = a.ema_212 ? ((a.current_price - a.ema_212) / a.ema_212) : -999;
                const dB = b.ema_212 ? ((b.current_price - b.ema_212) / b.ema_212) : -999;
                return dA - dB;
            },
            renderHeaderCell: () => t('col_trend'),
            renderCell: (item) => {
                if (!item.ema_212) return <span className={styles.smallText}>-</span>;
                const ema = Number(item.ema_212);
                const cur = Number(item.current_price);
                const diff = ((cur - ema) / ema) * 100;
                return (
                    <div style={{ display: 'flex', flexDirection: 'column', fontSize: '11px' }}>
                        <span style={{ fontWeight: 600, color: diff > 0 ? tokens.colorPaletteGreenForeground1 : tokens.colorPaletteRedForeground1 }}>
                            {diff > 0 ? '+' : ''}{diff.toFixed(1)}%
                        </span>
                        <span style={{ color: tokens.colorNeutralForeground3 }}>{t('trend_ema')}: {ema.toFixed(0)}</span>
                    </div>
                );
            }
        }),
        createTableColumn<MarketItem>({
            columnId: 'actions',
            renderHeaderCell: () => t('col_actions'),
            renderCell: (item) => (
                <Button icon={<Line24Regular />} size="small" appearance="subtle" onClick={() => setChartTicker(item.ticker)}>Graf</Button>
            )
        })
    ];



    return (
        <PageLayout>
            <Dialog open={historyUpdateOpen} onOpenChange={(_, d) => { if (!isUpdatingHistory) setHistoryUpdateOpen(d.open); }}>
                <DialogSurface>
                    <DialogBody>
                        <DialogTitle>Aktualizace Analýzy a Historie (Yahoo)</DialogTitle>
                        <DialogContent>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                                {isUpdatingHistory && (
                                    <>
                                        <Spinner label={`Zpracovávám ${historyProgress.lastTicker} (${historyProgress.current} / ${historyProgress.total})`} />
                                        <div style={{ height: '4px', background: '#f3f2f1', borderRadius: '2px', overflow: 'hidden' }}>
                                            <div style={{
                                                width: `${historyProgress.total ? (historyProgress.current / historyProgress.total) * 100 : 0}%`,
                                                background: '#0078d4',
                                                height: '100%',
                                                transition: 'width 0.3s'
                                            }} />
                                        </div>
                                    </>
                                )}
                                <div style={{
                                    maxHeight: '200px',
                                    overflowY: 'auto',
                                    background: '#f3f2f1',
                                    padding: '10px',
                                    fontSize: '11px',
                                    fontFamily: 'monospace',
                                    borderRadius: '4px'
                                }}>
                                    {historyLog.map((log, i) => <div key={i}>{log}</div>)}
                                </div>
                            </div>
                        </DialogContent>
                        <DialogActions>
                            <Button disabled={isUpdatingHistory} onClick={() => setHistoryUpdateOpen(false)}>Zavřít</Button>
                        </DialogActions>
                    </DialogBody>
                </DialogSurface>
            </Dialog>

            <PageHeader>
                <Toolbar>
                    <Dialog open={isAddOpen} onOpenChange={(_, data) => setAddOpen(data.open)}>
                        <DialogTrigger disableButtonEnhancement>
                            <ToolbarButton aria-label="New" icon={<Add24Regular />}>{t('btn_new')}</ToolbarButton>
                        </DialogTrigger>
                        <DialogSurface>
                            <DialogBody>
                                <DialogTitle>{t('add_ticker_title')}</DialogTitle>
                                <DialogContent>
                                    <Input
                                        value={newTicker}
                                        onChange={(_, data) => setNewTicker(data.value)}
                                        placeholder="např. MSFT, AAPL"
                                        onKeyDown={(e) => { if (e.key === 'Enter') handleAddTicker(); }}
                                    />
                                </DialogContent>
                                <DialogActions>
                                    <DialogTrigger disableButtonEnhancement>
                                        <Button appearance="secondary">{t('btn_cancel')}</Button>
                                    </DialogTrigger>
                                    <Button appearance="primary" onClick={handleAddTicker} disabled={adding}>
                                        {adding ? t('btn_adding') : t('btn_add')}
                                    </Button>
                                </DialogActions>
                            </DialogBody>
                        </DialogSurface>
                    </Dialog>

                    <div style={{ display: 'flex', alignItems: 'center', marginLeft: '10px', gap: '8px' }}>
                        <Switch
                            label={showWatchedOnly ? t('filter_watched_on') : t('filter_watched_off')}
                            checked={showWatchedOnly}
                            onChange={(_ev, data) => setShowWatchedOnly(Boolean(data.checked))}
                        />
                    </div>

                    <ToolbarDivider />
                    <ToolbarButton aria-label="Refresh" icon={<ArrowClockwise24Regular />} onClick={() => window.location.reload()}>{t('btn_refresh')}</ToolbarButton>
                    <ToolbarButton aria-label="Prices" icon={<Flash24Regular />} onClick={handleUpdatePrices}>{t('btn_quick_prices')}</ToolbarButton>
                    <ToolbarButton aria-label="Data" icon={<ArrowDownload24Regular />} onClick={handleUpdateHistory}>{t('btn_analysis_data')}</ToolbarButton>
                </Toolbar>
            </PageHeader>

            <PageContent noScroll>
                {loading ? <Spinner label={loadingText || t('loading_data')} /> : (
                    <div className={styles.gridCard} style={{ flex: 1, minHeight: 0 }}>
                        <div style={{ minWidth: '800px', height: '100%' }}> {/* Force scroll */}
                            <SmartDataGrid
                                items={filteredItems}
                                columns={columns}
                                getRowId={(item) => item.ticker}
                                withFilterRow={false} // Disable old filter row in favor of D365 header filters
                            />
                        </div>
                    </div>
                )}
            </PageContent>

            {/* Chart Modal */}
            {chartTicker && (
                <ChartModal
                    open={!!chartTicker}
                    ticker={chartTicker}
                    currency={items.find(i => i.ticker === chartTicker)?.currency || ''}
                    companyName={items.find(i => i.ticker === chartTicker)?.company_name || ''}
                    onClose={() => setChartTicker(null)}
                />
            )}
        </PageLayout>
    );

};

export default MarketPage;
