(function (root) {
    function packagePages(pages, width = 841.89, height = 595.28) {
        const encoder = new TextEncoder(), objects = [], chunks = [], offsets = [0];
        const text = value => encoder.encode(value);
        const stream = (dictionary, bytes) => [text('<< ' + dictionary + ' /Length ' + bytes.length + ' >>\nstream\n'), bytes, text('\nendstream')];
        objects.push([text('<< /Type /Catalog /Pages 2 0 R >>')]);
        objects.push([text('<< /Type /Pages /Count ' + pages.length + ' /Kids [' + pages.map((_, index) => (3 + index * 3) + ' 0 R').join(' ') + '] >>')]);
        pages.forEach((page, index) => {
            const number = 3 + index * 3;
            objects.push([text('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + width + ' ' + height + '] /Resources << /XObject << /Report ' + (number + 1) + ' 0 R >> >> /Contents ' + (number + 2) + ' 0 R >>')]);
            objects.push(stream('/Type /XObject /Subtype /Image /Width ' + page.width + ' /Height ' + page.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode', page.bytes));
            objects.push(stream('', text('q\n' + width + ' 0 0 ' + height + ' 0 0 cm\n/Report Do\nQ')));
        });
        chunks.push(text('%PDF-1.4\n%Report\n'));
        let length = chunks[0].length;
        objects.forEach((parts, index) => {
            offsets.push(length);
            const object = [text((index + 1) + ' 0 obj\n'), ...parts, text('\nendobj\n')];
            for (const part of object) {chunks.push(part); length += part.length;}
        });
        const xref = length;
        chunks.push(text('xref\n0 ' + (objects.length + 1) + '\n0000000000 65535 f \n' + offsets.slice(1).map(offset => String(offset).padStart(10, '0') + ' 00000 n \n').join('') + 'trailer\n<< /Size ' + (objects.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n'));
        const result = new Uint8Array(chunks.reduce((sum, chunk) => sum + chunk.length, 0)); let position = 0;
        for (const chunk of chunks) {result.set(chunk, position); position += chunk.length;}
        return result;
    }
    function wrapText(context, value, width) {
        const lines = []; let line = '';
        for (const word of String(value ?? '').split(/\s+/)) {
            const candidate = line ? line + ' ' + word : word;
            if (context.measureText(candidate).width <= width) {line = candidate; continue;}
            if (line) {lines.push(line); line = '';}
            for (const character of word) {
                if (line && context.measureText(line + character).width > width) {lines.push(line); line = '';}
                line += character;
            }
        }
        lines.push(line);
        return lines;
    }
    async function create(meta, columns, rows) {
        if (!columns.length || !rows.every(row => row.cells.length === columns.length)) throw Error('The report columns are incomplete. Refresh the page and try again.');
        await document.fonts?.ready;
        const width = 841.89, height = 595.28, scale = 3, margin = 36, tableWidth = width - margin * 2;
        const numericStart = columns[0] === 'Code' ? 2 : 1;
        const widths = columns[0] === 'Code' ? [0.1, 0.46, 0.14, 0.14, 0.16].map(value => value * tableWidth) : columns.map((_, index) => tableWidth * (index === 0 ? 0.52 : 0.48 / (columns.length - 1)));
        const pages = []; let context, canvas, y, bodyTop;
        const font = (size = 8.5, bold = false) => {context.font = (bold ? 'bold ' : '') + size + 'px Arial, sans-serif';};
        const draw = (value, x, top, size = 8.5, bold = false, align = 'left') => {font(size, bold); context.textAlign = align; context.fillStyle = '#333'; context.fillText(String(value ?? ''), x, top);};
        const heading = () => {
            canvas = document.createElement('canvas'); canvas.width = Math.round(width * scale); canvas.height = Math.round(height * scale);
            context = canvas.getContext('2d'); if (!context) throw Error('PDF rendering is unavailable in this browser.');
            context.scale(scale, scale); context.fillStyle = '#fff'; context.fillRect(0, 0, width, height); context.textBaseline = 'top';
            const blockWidth = tableWidth / 2 - 16;
            const block = (values, x, align) => {
                let top = margin;
                values.forEach((value, index) => {const size = index === 0 ? 11 : 8.5; font(size, index === 0); for (const line of wrapText(context, value, blockWidth)) {draw(line, x, top, size, index === 0, align); top += index === 0 ? 16 : 11;}});
                return top;
            };
            const left = block([meta.companyName, meta.trade, meta.registration, meta.address], margin, 'left');
            const right = block([meta.title, meta.period, meta.range, meta.branch, meta.software, meta.generated], width - margin, 'right');
            y = Math.max(left, right) + 10;
            context.strokeStyle = '#aaa'; context.lineWidth = 0.5; context.beginPath(); context.moveTo(margin, y); context.lineTo(width - margin, y); context.stroke(); y += 10;
            font(8.5, true);
            const labels = columns.map((value, index) => wrapText(context, value, widths[index] - 8));
            const headingHeight = Math.max(...labels.map(lines => lines.length)) * 11 + 8;
            context.fillStyle = '#dedede'; context.fillRect(margin, y, tableWidth, headingHeight);
            let x = margin;
            labels.forEach((lines, index) => {context.strokeRect(x, y, widths[index], headingHeight); lines.forEach((line, offset) => draw(line, index >= numericStart ? x + widths[index] - 4 : x + 4, y + 4 + offset * 11, 8.5, true, index >= numericStart ? 'right' : 'left')); x += widths[index];});
            y += headingHeight; bodyTop = y; pages.push({canvas, context});
        };
        heading();
        rows.forEach((row, index) => {
            const bold = ['grand', 'total', 'section'].includes(row.kind);
            font(8.5, bold);
            const cells = row.cells.map((value, column) => wrapText(context, typeof value === 'number' ? (value < 0 ? '(' : '') + Math.abs(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + (value < 0 ? ')' : '') : value, widths[column] - 8));
            const lineCount = Math.max(...cells.map(lines => lines.length));
            let offset = 0;
            while (offset < lineCount) {
                let capacity = Math.floor((height - margin - 26 - y - 8) / 11);
                if (capacity < 1 || offset === 0 && lineCount > capacity && y > bodyTop) {heading(); capacity = Math.floor((height - margin - 26 - y - 8) / 11);}
                if (capacity < 1) throw Error('The company heading is too long to fit on a PDF page. Shorten the profile details and try again.');
                const count = Math.min(lineCount - offset, capacity), rowHeight = count * 11 + 8;
                context.fillStyle = bold ? '#eee' : index % 2 === 0 ? '#f4f4f4' : '#fff'; context.fillRect(margin, y, tableWidth, rowHeight);
                let x = margin;
                cells.forEach((lines, column) => {lines.slice(offset, offset + count).forEach((line, lineIndex) => draw(line, column >= numericStart ? x + widths[column] - 4 : x + 4, y + 4 + lineIndex * 11, 8.5, bold, column >= numericStart ? 'right' : 'left')); x += widths[column];});
                y += rowHeight; offset += count;
            }
        });
        const images = [];
        for (const [index, page] of pages.entries()) {
            page.context.fillStyle = '#555'; page.context.textAlign = 'left'; page.context.font = '8px Arial, sans-serif';
            page.context.fillText(meta.lines?.[5]?.includes('Filtered detail') ? 'Filtered detail; totals cover the full report. Amounts in PHP.' : 'Amounts in Philippine pesos (PHP)', margin, height - margin);
            page.context.textAlign = 'right'; page.context.fillText('Page ' + (index + 1) + ' of ' + pages.length, width - margin, height - margin);
            const blob = await new Promise(resolve => page.canvas.toBlob(resolve, 'image/jpeg', 0.95));
            if (!blob) throw Error('The PDF page could not be generated. Please try again.');
            images.push({width: page.canvas.width, height: page.canvas.height, bytes: new Uint8Array(await blob.arrayBuffer())});
            page.canvas.width = 0; page.canvas.height = 0;
        }
        return packagePages(images, width, height);
    }
    const api = {create, packagePages, wrapText};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.FinancialReportPdf = api;
})(typeof window === 'undefined' ? null : window);
