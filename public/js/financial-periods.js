(function (root) {
    const date = (year, month, day) => new Date(Date.UTC(year, month, day)).toISOString().slice(0, 10);
    function fiscalYear(year, endMonth = 12) {
        if (!Number.isInteger(year) || year < 1900 || year > 2199 || !Number.isInteger(endMonth) || endMonth < 1 || endMonth > 12) throw Error('Choose a valid reporting year.');
        return {from: date(endMonth === 12 ? year : year - 1, endMonth % 12, 1), to: date(year, endMonth, 0)};
    }
    function yearFor(range, endMonth = 12) {
        const year = Number(range.to.slice(0, 4)), month = Number(range.to.slice(5, 7));
        return year + (month > endMonth ? 1 : 0);
    }
    function quarter(year, number, endMonth = 12) {
        if (!Number.isInteger(number) || number < 1 || number > 4) throw Error('Choose a quarter from Q1 to Q4.');
        const start = fiscalYear(year, endMonth).from.split('-').map(Number);
        const month = start[1] - 1 + (number - 1) * 3;
        return {from: date(start[0], month, 1), to: date(start[0], month + 3, 0)};
    }
    function identify(range, endMonth = 12) {
        const year = yearFor(range, endMonth), annual = fiscalYear(year, endMonth);
        if (range.from === annual.from && range.to === annual.to) return {kind: 'annual', year};
        for (let number = 1; number <= 4; number++) {
            const period = quarter(year, number, endMonth);
            if (range.from === period.from && range.to === period.to) return {kind: 'quarterly', year, quarter: number};
        }
        return {kind: 'custom', year};
    }
    const api = {fiscalYear, yearFor, quarter, identify};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.FinancialPeriods = api;
})(typeof window === 'undefined' ? null : window);
