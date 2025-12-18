
export interface Transaction {
    id?: string;
    date: string;
    amount: number | null;
    price: number | null;
    amount_cur: number | null;
    currency: string;
    platform: string;
    product_type: string;
    trans_type: string;
    notes?: string;
    fees?: number | null;
    isin?: string;
    company_name?: string;

    // Temp props
    __tmp?: any;
    status?: string | null;
}

export default class BaseParser {
    async parse(_content: any): Promise<Transaction[]> {
        throw new Error('Parse method must be implemented');
    }

    parseNumber(str: string | number | null | undefined): number | null {
        if (str == null) return null;
        if (typeof str === 'number') return str;

        let value = String(str).trim()
            .replace(/\u00A0/g, ' ')
            .replace(/ /g, '');

        const hasDot = value.includes('.');
        const hasComma = value.includes(',');

        if (hasDot && hasComma) {
            const lastDot = value.lastIndexOf('.');
            const lastComma = value.lastIndexOf(',');
            // If comma is after dot (e.g. 1.234,56), then comma is decimal
            if (lastComma > lastDot) {
                value = value.replace(/\./g, '').replace(',', '.');
            } else {
                value = value.replace(/,/g, '');
            }
        } else if (hasComma && !hasDot) {
            // "123,45" -> "123.45"
            value = value.replace(',', '.');
        }

        value = value.replace(/[^0-9.\-]/g, '');
        const num = parseFloat(value);

        return Number.isFinite(num) ? num : null;
    }

    csDateToISO(dateStr: string): string {
        const match = String(dateStr).match(/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/);
        if (!match) return '';
        const day = match[1].padStart(2, '0');
        const month = match[2].padStart(2, '0');
        const year = match[3];
        return `${year}-${month}-${day}`;
    }

    mapHeaders(headers: string[]): Record<string, number> {
        const map: Record<string, number> = {};
        headers.forEach((h, i) => {
            if (h) map[String(h).trim()] = i;
        });
        return map;
    }

    getVal(row: any[], map: Record<string, number>, colName: string): any {
        if (map[colName] == null) return null;
        return row[map[colName]];
    }

    parseCSV(text: string): string[][] {
        const firstLine = text.split(/\r?\n/)[0] || '';
        const delimiters = [',', ';', '\t'];
        let delimiter = ',';
        let maxCount = 0;

        for (const delim of delimiters) {
            const count = (firstLine.match(new RegExp(`\\${delim}`, 'g')) || []).length;
            if (count > maxCount) { maxCount = count; delimiter = delim; }
        }

        const rows: string[][] = [];
        let currentRow: string[] = [];
        let currentCell = '';
        let inQuotes = false;

        for (let i = 0; i < text.length; i++) {
            const char = text[i];
            const nextChar = text[i + 1];

            if (inQuotes) {
                if (char === '"' && nextChar === '"') {
                    currentCell += '"';
                    i++;
                } else if (char === '"') {
                    inQuotes = false;
                } else {
                    currentCell += char;
                }
            } else {
                if (char === '"') {
                    inQuotes = true;
                } else if (char === delimiter) {
                    currentRow.push(currentCell.trim());
                    currentCell = '';
                } else if (char === '\n') {
                    currentRow.push(currentCell.trim());
                    if (currentRow.some(cell => cell !== '')) rows.push(currentRow);
                    currentRow = [];
                    currentCell = '';
                } else if (char !== '\r') {
                    currentCell += char;
                }
            }
        }
        if (currentCell.length || currentRow.length) {
            currentRow.push(currentCell.trim());
            if (currentRow.some(cell => cell !== '')) rows.push(currentRow);
        }
        return rows;
    }
}
