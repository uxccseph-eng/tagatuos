window.FinancialReports = {
    build(ledger, report, year, fiscalEnd = 12, range = null) {
        const pad = value => String(value).padStart(2,'0'), endMonth = Number(fiscalEnd) || 12;
        const endDate = range?.to || new Date(Date.UTC(year,endMonth,0)).toISOString().slice(0,10), startDate = range?.from || (endMonth === 12 ? year : year-1)+'-'+pad(endMonth%12+1)+'-01';
        const all = ledger.bookEntries().filter(entry=>entry.date <= endDate), before = all.filter(entry=>entry.date < startDate), current = all.filter(entry=>entry.date >= startDate);
        const accounts = new Map(), rows = [], warnings = new Set();
        const codeFor = line => String(line.account).split(' · ')[0];
        for (const entry of all) { for (const line of entry.lines) { const code = codeFor(line); if (!accounts.has(code)) { const profile = ledger.accountProfile(code); accounts.set(code,{...profile,code,name:profile.name || String(line.account).split(' · ').slice(1).join(' · ') || code,opening:0,debit:0,credit:0,net:0}); } const account = accounts.get(code); const net = line.debit-line.credit; account.net += net; if (entry.date < startDate) { account.opening += net; } else { account.debit += line.debit; account.credit += line.credit; } } }
        const sorted = [...accounts.values()].sort((a,b)=>a.code.localeCompare(b.code)), balance = (account,opening=false) => (opening ? account.opening : account.net)*(['Liability','Equity','Income'].includes(account.type) ? -1 : 1);
        const add = (key,label,value=0,kind='account',code='') => rows.push({key,label,value,kind,code});
        const profit = entries => entries.reduce((total,entry)=>total+entry.lines.reduce((sum,line)=>sum+(['Income','Expense'].includes(ledger.accountProfile(codeFor(line)).type) ? line.credit-line.debit : 0),0),0);
        const accountTotal = (type,opening=false) => sorted.filter(a=>a.type === type).reduce((sum,a)=>sum+balance(a,opening),0);
        const netIncome = profit(current), totalProfit = profit(all), openingProfit = profit(before);
        const sectionFor = account => account.statement_section || ({Asset:/^15/.test(account.code)?'Non-Current Assets':'Current Assets',Liability:'Current Liabilities',Equity:'Capital',Income:'Revenue',Expense:'General and Administrative Expenses'})[account.type] || 'Unmapped accounts';
        function grouped(type, prefix, movement=false) {
            const sections = new Map();
            for (const account of sorted.filter(a=>a.type === type)) { const value = movement ? (account.debit-account.credit)*(type === 'Income' ? -1 : 1) : balance(account); if (!value && !account.debit && !account.credit) { continue; } const section = sectionFor(account); if (!sections.has(section)) { sections.set(section,[]); } sections.get(section).push({account,value}); }
            const order=['Current Assets','Non-Current Assets','Current Liabilities','Non-Current Liabilities','Capital','Retained Earnings','Revenue','Cost of Goods Sold'];
            for (const [section,values] of [...sections].sort(([a],[b])=>(order.indexOf(a)<0?99:order.indexOf(a))-(order.indexOf(b)<0?99:order.indexOf(b)) || a.localeCompare(b))) { add(prefix+':'+section,section,0,'section'); for (const {account,value} of values) { add(prefix+':'+account.code,account.name,value,'account',account.code); } add(prefix+':'+section+':total','Total '+section,values.reduce((sum,item)=>sum+item.value,0),'total'); }
        }
        let variance=0, columns=['Account',String(year)];
        if (report === 'trial-balance') {
            columns=['Code','Account','Debit','Credit','Balance']; let debit=0,credit=0;
            for (const account of sorted) { const dr=Math.max(0,account.opening)+account.debit, cr=Math.max(0,-account.opening)+account.credit; if (!dr && !cr) { continue; } rows.push({key:account.code,code:account.code,label:account.name,kind:'account',values:[dr,cr,dr-cr]}); debit+=dr;credit+=cr; }
            rows.push({key:'total',label:'Totals',kind:'grand',values:[debit,credit,debit-credit]}); variance=debit-credit;
        } else if (report === 'balance-sheet') {
            grouped('Asset','asset'); add('assets','TOTAL ASSETS',accountTotal('Asset'),'grand'); grouped('Liability','liability'); add('liabilities','TOTAL LIABILITIES',accountTotal('Liability'),'grand'); grouped('Equity','equity'); add('profit','Net income to date',totalProfit); const equity=accountTotal('Equity')+totalProfit; add('equity-total','TOTAL EQUITY',equity,'grand'); add('liabilities-equity','TOTAL LIABILITIES AND EQUITY',accountTotal('Liability')+equity,'grand'); variance=accountTotal('Asset')-accountTotal('Liability')-equity;
        } else if (report === 'income-statement') {
            grouped('Income','income',true); const revenue = sorted.filter(a=>a.type==='Income').reduce((sum,a)=>sum+a.credit-a.debit,0), expenses = sorted.filter(a=>a.type==='Expense');
            const expenseSum = filter => expenses.filter(filter).reduce((sum,a)=>sum+a.debit-a.credit,0), cost = expenseSum(a=>sectionFor(a)==='Cost of Goods Sold');
            const cogs = expenses.filter(a=>sectionFor(a)==='Cost of Goods Sold'); if (cogs.length) { add('cogs-section','Cost of Goods Sold',0,'section'); for (const a of cogs) { add('cost:'+a.code,a.name,a.debit-a.credit,'account',a.code); } add('cogs','Total Cost of Goods Sold',cost,'total'); }
            add('gross-margin','GROSS MARGIN',revenue-cost,'grand');
            const remaining = expenses.filter(a=>sectionFor(a)!=='Cost of Goods Sold'), sections=[...new Set(remaining.map(sectionFor))].sort();
            for (const section of sections) { add('expense-section:'+section,section,0,'section'); const list=remaining.filter(a=>sectionFor(a)===section); for (const a of list) { add('expense:'+a.code,a.name,a.debit-a.credit,'account',a.code); } add('expense-total:'+section,'Total '+section,list.reduce((sum,a)=>sum+a.debit-a.credit,0),'total'); }
            add('net-income','NET INCOME',netIncome,'grand');
        } else if (report === 'changes-in-equity') {
            add('opening-section','Opening balance',0,'section');
            for (const account of sorted.filter(a=>a.type==='Equity' && a.opening)) { add('opening:'+account.code,account.name,balance(account,true),'account',account.code); }
            add('opening-profit','Net income before the period',openingProfit); const opening=accountTotal('Equity',true)+openingProfit; add('opening-total','Total equity, opening',opening,'total');
            add('movement-section','Changes during the period',0,'section'); for (const account of sorted.filter(a=>a.type==='Equity' && (a.debit || a.credit))) { add('movement:'+account.code,account.name,account.credit-account.debit,'account',account.code); }
            add('period-profit','Net income for the period',netIncome); add('closing-section','Closing balance',0,'section'); const closing=accountTotal('Equity')+totalProfit; add('closing-total','TOTAL EQUITY, CLOSING',closing,'grand'); variance=closing-(opening+sorted.filter(a=>a.type==='Equity').reduce((sum,a)=>sum+a.credit-a.debit,0)+netIncome);
        } else if (report === 'cash-flow') {
            const isCash = line => Boolean(ledger.accountProfile(codeFor(line)).is_cash_bank), cash = entries => entries.reduce((sum,entry)=>sum+entry.lines.filter(isCash).reduce((value,line)=>value+line.debit-line.credit,0),0);
            const movements=new Map(), categoryFor = profile => {
                if (['Operating','Investing','Financing'].includes(profile.cash_flow_section)) { return profile.cash_flow_section; }
                if (profile.type==='Equity') { return 'Financing'; }
                if (profile.type==='Asset' && (profile.statement_section==='Non-Current Assets' || /^15/.test(profile.code))) { return 'Investing'; }
                if (profile.type==='Liability' && profile.statement_section==='Non-Current Liabilities') { return 'Financing'; }
                if (['Asset','Liability','Income','Expense'].includes(profile.type)) { return 'Operating'; }
                warnings.add('Some cash movements need cash flow mapping in Chart of Accounts.'); return 'Unclassified';
            };
            for (const entry of current) { if (!entry.lines.some(isCash)) { continue; } for (const line of entry.lines.filter(line=>!isCash(line))) { const profile=ledger.accountProfile(codeFor(line)), category=categoryFor(profile), key=category+':'+codeFor(line); if (!movements.has(key)) { movements.set(key,{category,code:codeFor(line),label:profile.name || line.account,amount:0}); } movements.get(key).amount+=line.credit-line.debit; } }
            const opening=cash(before), closing=cash(all), change=cash(current); add('cash-opening','Cash and cash equivalents, opening',opening,'total');
            for (const category of ['Operating','Investing','Financing','Unclassified']) { const lines=[...movements.values()].filter(item=>item.category===category && item.amount).sort((a,b)=>a.code.localeCompare(b.code)); if (category==='Unclassified' && !lines.length) { continue; } add('category:'+category,'Cash flows from '+category.toLowerCase()+' activities',0,'section'); for (const item of lines) { add('flow:'+item.category+':'+item.code,item.label,item.amount,'account',item.code); } add('flow-total:'+category,'Net cash from '+category.toLowerCase()+' activities',lines.reduce((sum,item)=>sum+item.amount,0),'total'); }
            const allocated=[...movements.values()].reduce((sum,item)=>sum+item.amount,0); add('cash-change','NET CHANGE IN CASH',change,'grand'); add('cash-closing','CASH AND CASH EQUIVALENTS, CLOSING',closing,'grand'); variance=change-allocated;
            if (variance) { warnings.add('Cash flow detail does not reconcile. Review unbalanced cash vouchers.'); }
        }
        const postedVariance=all.reduce((sum,entry)=>sum+entry.lines.reduce((amount,line)=>amount+line.debit-line.credit,0),0);
        if (postedVariance) { warnings.add('The posted ledger is out of balance. Review the source entries.'); }
        return {rows,columns,startDate,endDate,year,variance,postedVariance,entries:current.length,warnings:[...warnings]};
    }
};
