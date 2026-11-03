(() => {
    'use strict';
    const sheets = ['sales','purchases','collections','payments','cwtCertificates','wtpCertificates','journalEntries','payroll'];
    const clone = value => JSON.parse(JSON.stringify(value));
    const stable = value => Array.isArray(value) ? value.map(stable) : value && typeof value === 'object' ? Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])])) : value;
    const same = (a,b) => JSON.stringify(stable(a)) === JSON.stringify(stable(b));
    const rows = (state,payroll,sheet) => sheet === 'payroll' ? payroll : state[sheet] || [];
    const indexed = values => new Map(values.map(row => [String(row.id),row]));
    function changes(baseState,basePayroll,state,payroll,versions={}) {
        const operations=[];
        for (const sheet of sheets) {
            const before=indexed(rows(baseState,basePayroll,sheet)), after=indexed(rows(state,payroll,sheet));
            for(const id of new Set([...before.keys(),...after.keys()])) {
                const old=before.get(id), record=after.get(id);
                if(!same(old,record)) operations.push({sheet,id,expected_version:versions[sheet]?.[id] ?? (old ? 1 : 0),record:record ? clone(record) : null});
            }
        }
        return operations;
    }
    function rebase(baseState,basePayroll,state,payroll,latestState,latestPayroll) {
        const result={state:clone(latestState),payroll:clone(latestPayroll)};
        for (const sheet of sheets) {
            const before=indexed(rows(baseState,basePayroll,sheet)), after=indexed(rows(state,payroll,sheet)), latest=indexed(rows(result.state,result.payroll,sheet));
            for(const id of new Set([...before.keys(),...after.keys()])) {
                const old=before.get(id), local=after.get(id);
                if(same(old,local)) continue;
                if(!local) latest.delete(id);
                else if(!old || !latest.has(id)) latest.set(id,clone(local));
                else {
                    const merged=clone(latest.get(id));
                    for(const key of new Set([...Object.keys(old),...Object.keys(local)])) {
                        if(!same(old[key],local[key])) {
                            if(Object.hasOwn(local,key)) merged[key]=clone(local[key]); else delete merged[key];
                        }
                    }
                    latest.set(id,merged);
                }
            }
            if(sheet==='payroll') result.payroll=[...latest.values()]; else result.state[sheet]=[...latest.values()];
        }
        return result;
    }
    window.LedgerRecordChanges={changes,rebase,same};
})();
