
import fs from 'fs';
import path from 'path';

// Mock TextDecoder/Encoder if needed (Node has them globally now)
// Mock File object
class MockFile {
    constructor(path) {
        this.path = path;
        this.name = path.split('/').pop().split('\\').pop();
        this.size = fs.statSync(path).size;
    }
    async arrayBuffer() {
        return fs.readFileSync(this.path).buffer;
    }
}

// Minimal FileReaderService for CSV
class FileReaderService {
    async readCsv(file) {
        const buffer = await file.arrayBuffer();
        const text = new TextDecoder('utf-8').decode(buffer);
        return this.parseCSV(text);
    }

    parseCSV(text) {
        const firstLine = text.split(/\r?\n/)[0] || '';
        const delimiters = [',', ';', '\t'];
        let delimiter = ',';
        let maxCount = 0;

        for (const delim of delimiters) {
            const count = (firstLine.match(new RegExp(`\\${delim}`, 'g')) || []).length;
            if (count > maxCount) {
                maxCount = count;
                delimiter = delim;
            }
        }

        const rows = [];
        let currentRow = [];
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
                    currentCell = '';
                    if (currentRow.some(cell => cell !== '')) {
                        rows.push(currentRow);
                    }
                    currentRow = [];
                } else if (char !== '\r') {
                    currentCell += char;
                }
            }
        }
        if (currentCell.length || currentRow.length) {
            currentRow.push(currentCell.trim());
            if (currentRow.some(cell => cell !== '')) {
                rows.push(currentRow);
            }
        }
        return rows;
    }
}

// CsvRecognizer logic
class CsvRecognizer {
    async identify(content, filename = '') {
        if (!Array.isArray(content) || content.length < 2) {
            return 'unknown';
        }

        // --- HERE IS THE LOGIC WE WANT TO TEST ---
        const headers = content[0].map(h => (h || '').toString().trim().toLowerCase());
        console.log('Detected Headers:', headers);

        const rules = [
            {
                provider: 'revolut_trading',
                requiredHeaders: ['ticker', 'type', 'quantity'],
                optionalHeaders: ['price per share', 'total amount']
            },
            {
                provider: 'trading212',
                requiredHeaders: ['action', 'time', 'isin'],
                optionalHeaders: ['ticker', 'name', 'no. of shares']
            }
        ];

        for (const rule of rules) {
            const hasRequired = rule.requiredHeaders.every(h => {
                const has = headers.includes(h);
                if (!has) console.log(`Missing required header for ${rule.provider}: ${h}`);
                return has;
            });
            const hasOptional = rule.optionalHeaders ?
                rule.optionalHeaders.some(h => headers.includes(h)) : true;

            if (hasRequired && hasOptional) {
                return rule.provider;
            }
        }
        return 'unknown';
    }
}

async function run() {
    const filePath = 'C:/Users/Wendulka/Documents/Webhry/hollyhop/broker/Výpisy/Trade212/from_2024-01-17_to_2024-12-31_MTc2NTc5MTE2MDAzNw.csv';
    const file = new MockFile(filePath);
    const reader = new FileReaderService();

    console.log('Reading file...');
    const content = await reader.readCsv(file);
    console.log('Parsed Rows:', content.length);
    if (content.length > 0) {
        // Log raw first row
        console.log('Raw First Row:', content[0]);
    }

    const recognizer = new CsvRecognizer();
    const provider = await recognizer.identify(content, file.name);
    console.log('Identified Provider:', provider);
}

run().catch(console.error);
