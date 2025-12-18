
import {
    makeStyles,
    tokens,
    Spinner,
    Text,
    Badge,
    Toolbar,
    ToolbarButton
} from "@fluentui/react-components";
import { ArrowSync24Regular } from "@fluentui/react-icons";
import { useEffect, useState, useMemo, useCallback } from "react";
import axios from "axios";
import { SmartDataGrid } from "../components/SmartDataGrid";
import { PageLayout, PageContent, PageHeader } from "../components/PageLayout";
import { useTranslation } from "../context/TranslationContext";

const useStyles = makeStyles({
    tableContainer: {
        border: `1px solid ${tokens.colorNeutralStroke1}`,
        borderRadius: '8px',
        overflow: 'hidden',
        backgroundColor: tokens.colorNeutralBackground1,
        display: 'flex',
        flexDirection: 'column'
    },
    cellNum: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
    cellDate: { whiteSpace: 'nowrap', width: '100px' },
    buy: { color: tokens.colorPaletteGreenForeground1 },
    sell: { color: tokens.colorPaletteRedForeground1 },
    neutral: { color: tokens.colorNeutralForeground1 }
});

interface TransactionItem {
    trans_id: number;
    date: string;
    ticker: string;
    trans_type: string;
    amount: number;
    price: number;
    currency: string;
    amount_czk: number;
    platform: string;
    product_type: string;
    fees: string;
    ex_rate: number;
    amount_cur: number;
}

export const PortfolioPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [items, setItems] = useState<TransactionItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/investyx/api-transactions.php');
            if (res.data.success) {
                setItems(res.data.data);
            } else {
                setError(res.data.error || 'Failed to load');
            }
        } catch (e) {
            setError('Chyba komunikace se serverem.');
        } finally {
            setLoading(false);
        }
    };

    // Columns MUST be defined before conditional returns (React Hooks rules)
    const columns = useMemo(() => [
        {
            columnId: 'date',
            renderHeaderCell: () => t('col_date'),
            renderCell: (item: TransactionItem) => new Date(item.date).toLocaleDateString(t('locale') === 'en' ? 'en-US' : 'cs-CZ'),
            compare: (a: TransactionItem, b: TransactionItem) => new Date(a.date).getTime() - new Date(b.date).getTime(),
        },
        {
            columnId: 'trans_type',
            renderHeaderCell: () => t('col_type'),
            renderCell: (item: TransactionItem) => {
                const isBuy = item.trans_type.toLowerCase() === 'buy';
                const isSell = item.trans_type.toLowerCase() === 'sell';
                return (
                    <Badge appearance="outline" color={isBuy ? 'success' : (isSell ? 'danger' : 'brand')}>
                        {item.trans_type}
                    </Badge>
                );
            },
            compare: (a: TransactionItem, b: TransactionItem) => a.trans_type.localeCompare(b.trans_type),
        },
        {
            columnId: 'ticker',
            renderHeaderCell: () => t('col_ticker'),
            renderCell: (item: TransactionItem) => <Text weight="semibold">{item.ticker}</Text>,
            compare: (a: TransactionItem, b: TransactionItem) => a.ticker.localeCompare(b.ticker),
        },
        {
            columnId: 'amount',
            renderHeaderCell: () => t('col_quantity'),
            renderCell: (item: TransactionItem) => item.amount?.toLocaleString(undefined, { maximumFractionDigits: 6 }),
            compare: (a: TransactionItem, b: TransactionItem) => a.amount - b.amount,
        },
        {
            columnId: 'price',
            renderHeaderCell: () => t('col_prices_unit'),
            renderCell: (item: TransactionItem) => item.price?.toFixed(2),
            compare: (a: TransactionItem, b: TransactionItem) => a.price - b.price,
        },
        {
            columnId: 'currency',
            renderHeaderCell: () => t('col_currency'),
            renderCell: (item: TransactionItem) => <Badge size="small" appearance="tint">{item.currency}</Badge>,
            compare: (a: TransactionItem, b: TransactionItem) => a.currency.localeCompare(b.currency),
        },
        {
            columnId: 'amount_cur',
            renderHeaderCell: () => t('col_total_orig'),
            renderCell: (item: TransactionItem) => item.amount_cur?.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
            compare: (a: TransactionItem, b: TransactionItem) => a.amount_cur - b.amount_cur,
        },
        {
            columnId: 'ex_rate',
            renderHeaderCell: () => t('col_rate'),
            renderCell: (item: TransactionItem) => item.ex_rate?.toFixed(4),
            compare: (a: TransactionItem, b: TransactionItem) => a.ex_rate - b.ex_rate,
        },
        {
            columnId: 'amount_czk',
            renderHeaderCell: () => t('col_total_czk'),
            renderCell: (item: TransactionItem) => {
                const isBuy = item.trans_type.toLowerCase() === 'buy';
                const isSell = item.trans_type.toLowerCase() === 'sell';
                const colorClass = isBuy ? styles.buy : (isSell ? styles.sell : styles.neutral);
                return (
                    <Text weight="semibold" className={colorClass}>
                        {item.amount_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })}
                    </Text>
                );
            },
            compare: (a: TransactionItem, b: TransactionItem) => a.amount_czk - b.amount_czk,
        },
        {
            columnId: 'platform',
            renderHeaderCell: () => t('col_platform'),
            renderCell: (item: TransactionItem) => item.platform,
            compare: (a: TransactionItem, b: TransactionItem) => a.platform.localeCompare(b.platform),
        },
    ], [t, styles.buy, styles.sell, styles.neutral]);

    const getRowId = useCallback((item: TransactionItem) => item.trans_id, []);

    if (loading) return <Spinner label={t('loading_transactions')} />;
    if (error) return <Text>{error}</Text>;

    return (
        <PageLayout>
            <PageHeader>
                <Toolbar>
                    <ToolbarButton appearance="subtle" icon={<ArrowSync24Regular />} onClick={loadData}>
                        {t('refresh') || 'Obnovit'}
                    </ToolbarButton>
                </Toolbar>
            </PageHeader>
            <PageContent noScroll>
                <div className={styles.tableContainer} style={{ flex: 1, minHeight: 0 }}>
                    <div style={{ height: '100%' }}>
                        <SmartDataGrid
                            items={items}
                            columns={columns}
                            getRowId={getRowId}
                        />
                    </div>
                </div>
            </PageContent>
        </PageLayout>
    );
};
