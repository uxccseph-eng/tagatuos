(() => {
    const workspace = document.getElementById('workspace-ledger-config');
    if (!workspace) return;
    const config = JSON.parse(workspace.textContent);
    const storageKey = `kontadurya.ledger.v1.${workspace.dataset.key}`;
    const clone = value => JSON.parse(JSON.stringify(value));
    const isCompanyBook = /^company-[a-f0-9]{64}$/.test(workspace.dataset.key);
    let state, storedState;
    try { storedState = sessionStorage.getItem(storageKey); state = JSON.parse(storedState); }
    catch (error) { if (isCompanyBook) throw new Error('Company book storage could not be read. Existing data has not been replaced.'); state = null; }
    if (!state || !Array.isArray(state.sales) || !Array.isArray(state.collections)) {
        if (isCompanyBook && storedState !== null) throw new Error('Company book storage is invalid. Existing data has not been replaced.');
        if (isCompanyBook) {
            state = {sales:[],collections:[],purchases:[],payments:[],cwtCertificates:[],wtpCertificates:[],journalEntries:[],nextCollectionId:1};
        } else {
        const common={tax:'12% VAT',vatCwt:'None',classification:'Regular',status:'posted',notes:''};
        state = {sales:[
            {...common,id:1,number:'SI-000001',date:`${config.period}-16`,customer:'VARIOUS CUSTOMERS',gross:50000,account:'4020 · Sales - Services',cwt:'WC160 · 2%',terms:'Cash',reference:'OR-99120',bank:'1010 · Cash on Hand',memo:'Walk-in retail diagnostic consultations'},
            {...common,id:2,number:'SI-000002',date:`${config.period}-18`,customer:'Cruz Dental Clinic Corp.',gross:1000000,account:'4020 · Sales - Services',cwt:'WC160 · 2%',terms:'On Account',reference:'INV-2026-089',bank:'1030 · BDO Checking',memo:'Monthly Brand Advisory Retainer & SEO Management'},
            {...common,id:3,number:'SI-000003',date:`${config.period}-21`,customer:'Santos Trading Enterprises',gross:2500000,account:'4000 · Merchandise Sales',cwt:'WC100 · 1%',terms:'Cash',reference:'OR-99121',bank:'1010 · Cash on Hand',memo:'Batch fulfillment of seasonal promotional collateral'}
        ],collections:[],nextCollectionId:1};
        }
    }
    function compactAfterRemoval(key,previous) {
        if(!previous[key].some(record=>!state[key].some(item=>item.id===record.id)))return;
        const changed=new Map();
        [...state[key]].sort((a,b)=>Number(a.number.split('-').at(-1))-Number(b.number.split('-').at(-1))).forEach((record,index)=>{
            const number=record.number.replace(/\d+$/,String(index+1).padStart(6,'0'));
            if(record.number!==number)changed.set(record.number,number);
            record.number=number;
        });
        if(key==='sales'){
            state.collections=clone(state.collections).map(record=>({...record,invoice:changed.get(record.invoice) || record.invoice}));
            state.cwtCertificates=clone(state.cwtCertificates).map(record=>({...record,invoice:changed.get(record.invoice) || record.invoice}));
        }
        if(key==='purchases'){
            state.payments=clone(state.payments).map(record=>({...record,bill:changed.get(record.bill) || record.bill}));
            state.wtpCertificates=clone(state.wtpCertificates).map(record=>({...record,bill:changed.get(record.bill) || record.bill}));
        }
    }
    function synchronizeNumbers(key,input){for(const record of input){const saved=state[key].find(item=>item.id===record.id);if(saved){record.number=saved.number;if(['cwtCertificates','wtpCertificates'].includes(key))record.certificate=saved.certificate;}}}
    function persist() {
        try { sessionStorage.setItem(storageKey,JSON.stringify(state)); }
        catch { throw new Error('Browser session storage is unavailable. Records cannot be shared between these pages.'); }
    }
    function normalizeTerms(sales) {
        return sales.map(sale=>({...sale,terms:sale.terms==='Cash'?'Cash':'On Account'}));
    }
    state.sales=normalizeTerms(state.sales);
    if(!Array.isArray(state.cwtCertificates))state.cwtCertificates=[];
    if(!Array.isArray(state.wtpCertificates))state.wtpCertificates=[];
    if(!Array.isArray(state.journalEntries))state.journalEntries=[];
    const defaultJournalAccounts=['1010 · Cash on Hand','1020 · Cash in Bank','1105 · Accounts Receivable - Trade','1150 · Prepaid CWT','1170 · Advances to Suppliers','1200 · Inventory','1500 · Property and Equipment','1590 · Accumulated Depreciation','2010 · Accounts Payable - Trade','2100 · Accrued Expenses','2110 · Input VAT','2140 · Expanded WHT Payable','2150 · Withholding VAT Payable','2200 · Customer Advances','3010 · Owner Capital','3020 · Retained Earnings','4000 · Merchandise Sales','4020 · Sales - Services','5000 · Cost of Sales','6110 · Office Supplies','6150 · Packaging Supplies','7110 · Rent Expense','7120 · Utilities','7130 · Software and Subscriptions','7140 · Professional Fees','7150 · Depreciation Expense'];
    const accountCatalog=Array.isArray(window.WorkspaceAccounts)?window.WorkspaceAccounts:null;
    let journalAccounts=accountCatalog?accountCatalog.filter(account=>!account.archived).map(account=>account.code+' · '+account.name):defaultJournalAccounts;
    window.addEventListener('masters:account-created',()=>{journalAccounts=accountCatalog.filter(account=>!account.archived).map(account=>account.code+' · '+account.name);});
    function accountProfile(code){const recorded=accountCatalog?.find(account=>account.code===String(code));if(recorded)return clone(recorded);const type=code==='2110'||String(code).startsWith('1')?'Asset':String(code).startsWith('2')?'Liability':String(code).startsWith('3')?'Equity':String(code).startsWith('4')?'Income':'Expense';return {code,type,normal_balance:code==='1590'||['Liability','Equity','Income'].includes(type)?'Credit':'Debit',is_cash_bank:/^10\d{2}$/.test(code),archived:false};}
    function fillAccountOptions(){
        if(!accountCatalog)return;
        document.querySelectorAll('select').forEach(select=>{
            const label=select.getAttribute('aria-label') || '',purchase=select.dataset.purchaseField,payment=select.dataset.paymentField,collection=select.dataset.collectionField,wtp=select.dataset.wtpField;
            let choices=null;
            if(/Revenue account/i.test(label)||(select.closest('#sales-draft')&&[...select.options].some(option=>/^4\d{3} · /.test(option.value))))choices=accountCatalog.filter(account=>account.type==='Income'&&!account.archived);
            else if(purchase==='account'||payment==='account')choices=accountCatalog.filter(account=>['Asset','Expense'].includes(account.type)&&!account.archived);
            else if(/Deposit account/i.test(label)||purchase==='bank'||payment==='bank'||collection==='bank'||([...select.options].filter(option=>option.value).length>0&&[...select.options].filter(option=>option.value).every(option=>/^10\d{2} · /.test(option.value))))choices=accountCatalog.filter(account=>account.is_cash_bank&&!account.archived);
            else if(wtp==='offset')choices=accountCatalog.filter(account=>!account.archived);
            if(!choices)return;
            const previous=select.value,code=previous.split(' · ')[0],empty=[...select.options].find(option=>option.value==='');select.replaceChildren();if(empty)select.add(new Option(empty.textContent,''));for(const account of choices){const label=account.code+' · '+account.name;select.add(new Option(label,label));}const selected=choices.find(account=>account.code===code);select.value=selected?selected.code+' · '+selected.name:(empty?'':select.options[0]?.value || '');
        });
    }
    function setAccountSelect(select,value){select.querySelectorAll('option[data-saved-account]').forEach(option=>option.remove());if(value&&/^\d{4} · /.test(value)&&![...select.options].some(option=>option.value===value)){const option=new Option(value+' (saved)',value);option.dataset.savedAccount='true';select.add(option);}select.value=value;}
    fillAccountOptions();
    function journalAmounts(record){
        const totals=(record.lines || []).reduce((sum,line)=>({debit:sum.debit+(Number.isSafeInteger(line.debit)?line.debit:0),credit:sum.credit+(Number.isSafeInteger(line.credit)?line.credit:0)}),{debit:0,credit:0});
        return {...totals,variance:totals.debit-totals.credit};
    }
    function payrollRemittanceError(record){
        if(record.origin!=='payroll-remittance'||!record.payrollAllocations)return null;
        const expected={},runs=new Set(),keys=['salary','sss','phic','hdmf','withholding'];
        if(!Array.isArray(record.payrollAllocations)||!record.payrollAllocations.length)return 'A payroll remittance must link its source runs.';
        for(const allocation of record.payrollAllocations){
            if(!allocation.runId||runs.has(allocation.runId)||!allocation.amounts||!Object.keys(allocation.amounts).length)return 'Invalid payroll allocation.';
            runs.add(allocation.runId);
            for(const [key,amount] of Object.entries(allocation.amounts)){
                if(!keys.includes(key)||!Number.isSafeInteger(amount)||amount<=0)return 'Invalid payroll liability amount.';
                expected[key]=(expected[key] || 0)+amount;
            }
        }
        const total=Object.values(expected).reduce((sum,amount)=>sum+amount,0),seen=new Set();
        for(const line of record.lines || []){
            const key=line.payrollLiability,profile=accountProfile(String(line.account).split(' · ')[0]);
            if(seen.has(key))return 'Duplicate payroll liability line.';
            seen.add(key);
            if(key==='bank'){
                if(line.credit!==total||line.debit!==0||!profile.is_cash_bank||profile.type!=='Asset')return 'Payroll remittance must credit an active cash / bank account for its total.';
            }else if(!expected[key]||line.debit!==expected[key]||line.credit!==0||profile.type!=='Liability')return 'Payroll remittance amounts must match the selected run liabilities. Void and regenerate it from Payroll.';
        }
        if(seen.size!==Object.keys(expected).length+1||!seen.has('bank'))return 'Payroll remittance lines are incomplete.';
        return null;
    }
    function journalError(record){
        const remittanceError=payrollRemittanceError(record);if(remittanceError)return remittanceError;
        if(record.origin==='payroll-remittance'){
            const allocations=record.payrollAllocations || [{runId:record.payrollRunId,amounts:{salary:1,sss:1,phic:1,hdmf:1,withholding:1}}];
            const pairs=new Set(allocations.flatMap(item=>Object.keys(item.amounts).map(key=>JSON.stringify([item.runId,key]))));
            for(const other of state.journalEntries.filter(item=>item.id!==record.id&&item.origin==='payroll-remittance'&&item.status==='posted')){
                const paid=other.payrollAllocations || [{runId:other.payrollRunId,amounts:{salary:1,sss:1,phic:1,hdmf:1,withholding:1}}];
                if(paid.some(item=>Object.keys(item.amounts).some(key=>pairs.has(JSON.stringify([item.runId,key])))))return 'This payroll liability already has a posted remittance journal. Void the previous remittance first.';
            }
        }
        if(!/^\d{4}-\d{2}-\d{2}$/.test(record.date || '')||Number.isNaN(Date.parse(record.date+'T12:00:00Z'))||new Date(record.date+'T12:00:00Z').toISOString().slice(0,10)!==record.date)return 'Enter a valid journal date.';
        if(!['General','Adjusting','Opening','Closing','Reclassification'].includes(record.type))return 'Select a valid entry type.';
        if(!Array.isArray(record.lines)||record.lines.length<2||record.lines.length>100)return 'Use between 2 and 100 journal lines.';
        for(const [index,line] of record.lines.entries()){
            const retained=state.journalEntries.find(item=>item.id===record.id&&item.status==='posted')?.lines.some(item=>item.account===line.account);
            if(!journalAccounts.some(account=>account.split(' · ')[0]===String(line.account).split(' · ')[0])&&!retained)return `Line ${index+1}: select an active account.`;
            if(!Number.isSafeInteger(line.debit)||!Number.isSafeInteger(line.credit)||line.debit<0||line.credit<0||line.debit>99999999999||line.credit>99999999999)return `Line ${index+1}: enter valid debit and credit amounts.`;
            if((line.debit>0)===(line.credit>0))return `Line ${index+1}: enter either a debit or a credit, with a positive amount.`;
        }
        const totals=journalAmounts(record);
        if(!Number.isSafeInteger(totals.debit)||!Number.isSafeInteger(totals.credit))return 'Journal totals are too large.';
        if(totals.variance!==0)return 'Total debits must equal total credits before posting.';
        return null;
    }
    if(!Array.isArray(state.purchases)) {
        const common={tax:'VAT (12%)',terms:'Cash',bank:'1010 · Cash on Hand',wvat:0,tin:'',notes:'',status:'posted',attachments:[]};
        state.purchases=[
            {...common,id:1,number:'BILL-000001',date:`${config.period}-16`,supplier:'VARIOUS SUPPLIERS',gross:10000,account:'6150 · Packaging Supplies',ewt:0,reference:'SI-98421',description:'Packaging materials and seal tapes'},
            {...common,id:2,number:'BILL-000002',date:`${config.period}-16`,supplier:'VARIOUS SUPPLIERS',gross:20000,account:'7110 · Rent Expense',ewt:5,reference:'OR-10293',description:'Office monthly storage sub-rental'}
        ];
    }
    function purchaseAmounts(record) {
        const gross=Number.isSafeInteger(record.gross)&&record.gross>=0?record.gross:0;
        const net=window.SheetMasters?window.SheetMasters.taxAmounts(gross,record.tax).net:record.tax==='VAT (12%)'?Math.round(gross*100/112):gross;
        const ewt=Math.round(net*Number(record.ewt || 0)/100);
        const wvat=Math.round(net*Number(record.wvat || 0)/100);
        return {gross,net,vat:gross-net,ewt,wvat,payable:gross-ewt-wvat};
    }
    function wtpAmounts(record) {
        const base=Number.isSafeInteger(record.taxBase)&&record.taxBase>=0?record.taxBase:0;
        const ewt=Math.round(base*Number(record.ewt || 0)/100),wvat=Math.round(base*Number(record.wvat || 0)/100);
        return {base,ewt,wvat,total:ewt+wvat};
    }
    function wtpAtc(record) {
        const hasId=record.billId!==undefined&&record.billId!==null&&record.billId!=='';
        const namedBills=!hasId&&record.bill?state.purchases.filter(item=>item.number===record.bill):[];
        const bill=hasId?state.purchases.find(item=>String(item.id)===String(record.billId)):namedBills.length===1?namedBills[0]:null;
        if(record.status==='draft'&&bill&&bill!==record){const sourceCode=wtpAtc(bill);if(sourceCode)return sourceCode;}
        const savedCode=item=>[item?.ewtCode,item?.ewt_atc_code,item?.atc_code,item?.atc].find(value=>typeof value==='string'&&value.trim());
        const explicit=String(savedCode(record) || savedCode(bill) || '').trim();
        if(explicit)return explicit.match(/^W[IC][0-9]{3}(?=$|[ ·-])/i)?.[0]?.toUpperCase() || '';
        const account=accountProfile(String(bill?.account || record.account || '').split(' · ')[0]),code=String(account.ewt_atc_code || '').trim().toUpperCase();
        const atc=window.WorkspaceMasters?.atcCodes?.find(item=>item.code===code&&item.category==='Income-tax CWT');
        return !account.archived&&/^W[IC][0-9]{3}$/.test(code)&&atc&&Number(atc.rate)===Number(record.ewt)?code:'';
    }
    function wtpVatAtc(record) {
        const bill=record.billId?state.purchases.find(item=>String(item.id)===String(record.billId)):null;
        if(record.status==='draft'&&bill&&bill!==record){const sourceCode=wtpVatAtc(bill);if(sourceCode)return sourceCode;}
        const code=String(record.wvatCode || record.wvat_atc_code || bill?.wvatCode || bill?.wvat_atc_code || '').trim();
        return code.match(/^W[VB][0-9]{3}(?=$|[ ·-])/i)?.[0]?.toUpperCase() || '';
    }
    function wtpSource(billId) {
        const bill=state.purchases.find(record=>String(record.id)===String(billId)&&record.status==='posted');
        if(!bill)return null;
        const amounts=purchaseAmounts(bill);
        return {...clone(bill),ewtCode:wtpAtc(bill),wvatCode:wtpVatAtc(bill),taxBase:amounts.net,withheld:amounts.ewt+amounts.wvat};
    }
    function certificateNumber(record,certificates) {
        const entered=String(record.certificate || '').trim();if(entered)return entered;
        const base=String(record.number || '2307'),used=new Set(certificates.filter(item=>item.id!==record.id).map(item=>String(item.certificate || '').trim().toLowerCase()));
        let result=base,suffix=2;while(used.has(result.toLowerCase()))result=base+'-'+suffix++;
        return result;
    }
    function wtpError(record,certificates=state.wtpCertificates,validateSource=true) {
        if(!/^\d{4}-\d{2}-\d{2}$/.test(record.date || '')||!record.supplier?.trim())return 'Enter an issue date and supplier.';
        if(!/^\d{3}-?\d{3}-?\d{3}(?:-?\d{3,5})?$/.test(record.tin || ''))return 'Enter a supplier TIN in the format 000-000-000-00000.';
        if(!Number.isSafeInteger(record.taxBase)||record.taxBase<=0||record.taxBase>99999999999||!(window.SheetMasters?window.SheetMasters.allowedRate(record.ewt):[0,1,2,5,10,15].includes(Number(record.ewt)))||!(window.SheetMasters?window.SheetMasters.allowedRate(record.wvat,true):[0,5].includes(Number(record.wvat)))||wtpAmounts(record).total<=0)return 'Enter a positive net tax base and select at least one withholding rate.';
        if(!journalAccounts.includes(record.offset))return 'Choose a valid offset account.';
        if(certificates.some(item=>item.id!==record.id&&item.status==='posted'&&(String(item.certificate).trim().toLowerCase()===certificateNumber(record,certificates).toLowerCase()||record.billId&&item.billId===record.billId)))return 'This certificate number or source bill already has a posted WTP certificate.';
        if(record.billId&&validateSource){const bill=wtpSource(record.billId);if(!bill||bill.number!==record.bill||bill.supplier!==record.supplier||bill.taxBase!==record.taxBase||Number(bill.ewt)!==Number(record.ewt)||Number(bill.wvat)!==Number(record.wvat))return 'The source bill is missing or changed. Select it again to refresh the certificate.';}
        return '';
    }
    if(!Array.isArray(state.payments))state.payments=[];
    function nextPayment() {
        const id=state.payments.reduce((highest,record)=>Math.max(highest,Number(record.id) || 0),0)+1;
        const number=state.payments.reduce((highest,record)=>Math.max(highest,Number(String(record.number).split('-')[1]) || 0),0)+1;
        return {id,number:`PV-${String(number).padStart(6,'0')}`};
    }
    function syncCashPayments() {
        state.purchases.forEach(bill=>{
            let payment=state.payments.find(record=>record.origin==='cash-purchase'&&record.billId===bill.id);
            const cash=bill.status==='posted'&&bill.terms==='Cash';
            const otherPaid=state.payments.filter(record=>record.id!==payment?.id&&record.billId===bill.id&&record.status==='posted'&&record.type==='AP Settlement').reduce((sum,record)=>sum+record.amount,0);
            const remaining=Math.max(0,purchaseAmounts(bill).payable-otherPaid);
            if(!payment&&cash&&remaining>0){payment={...nextPayment(),origin:'cash-purchase',billId:bill.id,attachments:[],history:[],notes:bill.description || ''};state.payments.push(payment);}
            if(payment)Object.assign(payment,{date:bill.date,supplier:bill.supplier,tin:bill.tin || '',billId:bill.id,bill:bill.number,amount:payment.paymentVoided||payment.paymentArchived?payment.amount:remaining,bank:bill.bank,method:'Cash',reference:bill.reference || '',type:'AP Settlement',account:'2010 · Accounts Payable',status:payment.paymentArchived?'archived':cash&&!payment.paymentVoided?'posted':'void'});
        });
    }
    function payableBills() {
        return state.purchases.filter(bill=>bill.status==='posted').map(bill=>{
            const payable=purchaseAmounts(bill).payable;
            const paid=state.payments.filter(record=>record.status==='posted'&&record.billId===bill.id&&record.type==='AP Settlement').reduce((sum,record)=>sum+record.amount,0);
            return {...clone(bill),payable,paid,balance:Math.max(0,payable-paid)};
        });
    }
    function paymentError(record,payments=state.payments) {
        if(!/^\d{4}-\d{2}-\d{2}$/.test(record.date || '')||!record.supplier?.trim()||!Number.isSafeInteger(record.amount)||record.amount<=0||record.amount>99999999999||!record.bank||!record.method)return 'Enter the payment date, supplier, positive amount, cash/bank account and method.';
        if(!['AP Settlement','Direct Expense','Supplier Advance'].includes(record.type))return 'Choose a valid payment entry type.';
        if(record.type==='AP Settlement'){
            const bill=state.purchases.find(bill=>bill.id===record.billId&&bill.status==='posted');
            if(!bill)return 'Select an active posted purchase bill.';
            if(record.origin==='cash-purchase'&&bill.terms!=='Cash')return 'The linked bill is no longer a cash purchase. Enter a new payment instead.';
            const otherPaid=payments.filter(item=>item.id!==record.id&&item.billId===bill.id&&item.status==='posted'&&item.type==='AP Settlement').reduce((sum,item)=>sum+item.amount,0);
            if(otherPaid+record.amount>purchaseAmounts(bill).payable)return 'This payment exceeds the remaining bill balance. Review other payments first.';
            if(record.supplier!==bill.supplier)return 'The payment supplier must match the source bill.';
        }else{
            if(record.billId)return 'Direct expenses and advances must not settle a bill.';
            if(record.type==='Direct Expense'&&!record.account)return 'Select the direct expense account.';
        }
        return '';
    }
    function nextCollectionNumber() {
        return state.collections.reduce((highest,record)=>{
            const suffix=String(record.number || '').match(/^CR-(\d+)$/);
            const number=suffix?Number(suffix[1]):0;
            return Number.isSafeInteger(number)?Math.max(highest,number):highest;
        },0)+1;
    }
    const highestCollectionId=state.collections.reduce((highest,record)=>Number.isSafeInteger(record.id)?Math.max(highest,record.id):highest,0);
    state.nextCollectionId=Math.max(Number.isSafeInteger(state.nextCollectionId)?state.nextCollectionId:1,highestCollectionId+1);
    state.nextCollectionNumber=nextCollectionNumber();
    function receivable(sale) {
        const net = window.SheetMasters?window.SheetMasters.taxAmounts(sale.gross,sale.tax).net:sale.tax === '12% VAT' ? Math.round(sale.gross*100/112) : sale.gross;
        const rate = label => Number(String(label || '').match(/(\d+(?:\.\d+)?)%/)?.[1] || 0);
        return Math.max(0,sale.gross-Math.round(net*rate(sale.cwt)/100)-Math.round(net*rate(sale.vatCwt)/100));
    }
    function nextCollection() {
        const id=state.nextCollectionId++;
        const number=state.nextCollectionNumber++;
        return {id,number:`CR-${String(number).padStart(6,'0')}`};
    }
    function syncCashCollections() {
        state.sales.forEach(sale=>{
            let collection=state.collections.find(record=>record.origin==='cash-sale' && record.saleId===sale.id);
            const postedCash = sale.status==='posted' && sale.terms==='Cash';
            if(!collection && postedCash) {
                collection={...nextCollection(),origin:'cash-sale',saleId:sale.id};state.collections.push(collection);
            }
            if(collection)Object.assign(collection,{date:sale.date,customer:sale.customer,invoice:sale.number,amount:receivable(sale),bank:sale.bank,reference:sale.reference,method:'Cash',type:'Settlement',status:sale.status==='archived'||collection.collectionArchived?'archived':postedCash&&!collection.collectionVoided?'posted':'void'});
        });
    }
    function invoices() {
        return state.sales.filter(sale=>sale.status==='posted').map(sale=>{
            const total=receivable(sale);
            const collected=state.collections.filter(record=>record.status==='posted'&&record.invoice===sale.number&&record.type!=='Advance').reduce((sum,record)=>sum+record.amount,0);
            return {...clone(sale),total,collected,balance:Math.max(0,total-collected)};
        });
    }
    function updateCollection(id,change) {
        const record=state.collections.find(collection=>collection.id===id);
        if(!record)throw new Error('Collection not found.');
        const previous=clone(state);
        try {change(record);persist();return clone(record);} catch(error){state=previous;throw error;}
    }
    syncCashCollections();
    syncCashPayments();
    function bookEntries(){
        const entries=[];
        function voucher(record,sheet,source,lines){
            const activeLines=lines.filter(line=>line.debit>0||line.credit>0);
            entries.push({id:sheet+':'+record.id,number:record.number,date:record.date,reference:record.reference || record.certificate || '',sheet,source,lines:activeLines});
        }
        const line=(account,debit,credit,party='',remarks='')=>({account,debit,credit,party,remarks});
        for(const record of state.sales.filter(item=>item.status==='posted')){
            const base=window.SheetMasters?window.SheetMasters.taxAmounts(record.gross,record.tax).net:record.tax==='12% VAT'?Math.round(record.gross*100/112):record.gross;
            voucher(record,'sales','Sales',[line('1105 · Accounts Receivable - Trade',record.gross,0,record.customer,record.memo),line('2210 · Output VAT Payable',0,record.gross-base,'','Output tax on sales'),line(record.account,0,base,'',record.memo)]);
        }
        for(const record of state.purchases.filter(item=>item.status==='posted')){
            const totals=purchaseAmounts(record);
            voucher(record,'purchases','Purchases',[line(record.account,totals.net,0,record.supplier,record.description),line('2110 · Input VAT',totals.vat,0,record.supplier,'Input tax on purchase'),line('2010 · Accounts Payable - Trade',0,totals.payable,record.supplier,record.description),line('2140 · Expanded WHT Payable',0,totals.ewt,record.supplier,'Withholding recognized on source bill'),line('2150 · Withholding VAT Payable',0,totals.wvat,record.supplier,'Withholding VAT on source bill')]);
        }
        for(const record of state.collections.filter(item=>item.status==='posted'))voucher(record,'collections','Collections',[line(record.bank,record.amount,0,'',record.notes || record.reference),line(record.type==='Advance'?'2200 · Customer Advances':'1105 · Accounts Receivable - Trade',0,record.amount,record.customer,record.invoice)]);
        for(const record of state.payments.filter(item=>item.status==='posted'))voucher(record,'payments','Payments',[line(record.type==='AP Settlement'?'2010 · Accounts Payable - Trade':record.type==='Supplier Advance'?'1170 · Advances to Suppliers':record.account,record.amount,0,record.supplier,record.notes || record.bill),line(record.bank,0,record.amount,'',record.reference)]);
        for(const record of state.cwtCertificates.filter(item=>item.status==='posted'))voucher(record,'cwt','CWT',[line(record.offset || '1150 · Prepaid CWT',record.amount,0,record.customer,'Received withholding certificate'),line('1105 · Accounts Receivable - Trade',0,record.amount,record.customer,record.invoice)]);
        for(const record of state.wtpCertificates.filter(item=>item.status==='posted'&&!item.billId)){
            const totals=wtpAmounts(record);
            voucher(record,'wtp','WTP',[line(record.offset,totals.total,0,record.supplier,'Standalone issued certificate'),line('2140 · Expanded WHT Payable',0,totals.ewt,record.supplier,'Expanded withholding'),line('2150 · Withholding VAT Payable',0,totals.wvat,record.supplier,'Withholding VAT')]);
        }
        for(const record of state.journalEntries.filter(item=>item.status==='posted'))voucher(record,'journal','Journal · '+record.type,record.lines.map(item=>line(item.account,item.debit,item.credit,item.party,item.remarks || record.particulars)));
        for(const record of (window.WorkspaceDepreciationJournals || []))voucher(record,'lapsing','Depreciation',record.lines.map(item=>line(item.account,item.debit,item.credit,item.party,item.remarks)));
        return clone(entries.sort((a,b)=>a.date.localeCompare(b.date)||a.number.localeCompare(b.number)));
    }
    function importFingerprint(sheet,record){
        const fields={sales:['date','customer','gross','reference','account','tax','terms','cwt','vatCwt'],purchases:['date','supplier','gross','reference','account','tax','terms','ewt','wvat'],collections:['date','customer','invoice','amount','reference','type'],payments:['date','supplier','bill','amount','reference','type','account'],cwt:['date','invoice','customer','tin','taxBase','atc','amount'],wtp:['date','bill','supplier','tin','taxBase','ewt','wvat','ewtCode','wvatCode','offset'],journal:['date','reference','type','lines']}[sheet];
        return JSON.stringify(fields.map(field=>typeof record[field]==='string'?record[field].trim().toLowerCase():record[field] ?? ''));
    }
    function processImport(sheet,records,commit,user){
        const key={sales:'sales',purchases:'purchases',collections:'collections',payments:'payments',cwt:'cwtCertificates',wtp:'wtpCertificates',journal:'journalEntries'}[sheet],prefix={sales:'SI',purchases:'BILL',collections:'CR',payments:'PV',cwt:'2307R',wtp:'2307I',journal:'GJ'}[sheet];
        if(!key||!Array.isArray(records)||!records.length||records.length>500)throw new Error('Import between 1 and 500 entries into a valid sheet.');
        const previous=state,errors=[],imported=[],seen=new Set(state[key].filter(record=>['posted','draft'].includes(record.status)).map(record=>importFingerprint(sheet,record)));
        state=clone(state);
        try{
            for(const input of records){
                try{
                    const record=clone(input),signature=importFingerprint(sheet,record);delete record._row;delete record.voucherKey;
                    if(seen.has(signature))throw new Error('Duplicate entry: already in this file or the current sheet.');
                    record.id=state[key].reduce((highest,item)=>Math.max(highest,Number(item.id) || 0),0)+1;
                    if(sheet==='collections')record.id=state.nextCollectionId++;
                    const suffix=state[key].reduce((highest,item)=>Math.max(highest,Number(String(item.number).split('-').at(-1)) || 0),0)+1;
                    record.number=prefix+'-'+String(suffix).padStart(6,'0');record.status='posted';if(['cwt','wtp'].includes(sheet))record.certificate=certificateNumber(record,state[key]);record.origin='manual';record.attachments=[];record.createdAt=new Date().toISOString();record.postedAt=record.createdAt;record.history=[{label:'Imported and posted',user,time:record.createdAt}];
                    if(!/^\d{4}-\d{2}-\d{2}$/.test(record.date || '')||Number.isNaN(Date.parse(record.date+'T12:00:00Z'))||new Date(record.date+'T12:00:00Z').toISOString().slice(0,10)!==record.date)throw new Error('Enter a valid transaction date.');
                    if(window.SheetMasters){const tin=record.tin;Object.assign(record,window.SheetMasters.partySnapshot(record));if(tin)record.tin=tin;}
                    if(['sales','purchases'].includes(sheet)){
                        if(!(record.customer || record.supplier)?.trim()||!Number.isSafeInteger(record.gross)||record.gross<=0||record.gross>99999999999||!record.account||!['Cash','On Account'].includes(record.terms)||!record.bank)throw new Error('Complete the party, positive amount, account, terms and cash/bank account.');
                        if(record.reference&&state[key].some(item=>['posted','draft'].includes(item.status)&&String(item.reference).trim().toLowerCase()===record.reference.trim().toLowerCase()&&(item.customer || item.supplier)===(record.customer || record.supplier)))throw new Error('This party and invoice reference already exist.');
                    }
                    if(sheet==='collections'){
                        if(!record.customer?.trim()||!Number.isSafeInteger(record.amount)||record.amount<=0||record.amount>99999999999||!record.bank||!record.method||!['Advance','Settlement','Downpayment'].includes(record.type))throw new Error('Complete the customer, positive amount, bank, method and entry type.');
                        const linked=record.invoice!=='Unlinked / Advance Deposit',invoice=invoices().find(item=>item.number===record.invoice);
                        if(record.type==='Advance'&&linked||record.type!=='Advance'&&!linked)throw new Error('Choose an invoice for a settlement/downpayment, or use an unlinked advance.');
                        if(linked&&(!invoice||invoice.customer!==record.customer))throw new Error('The customer must match an active posted source invoice.');
                        if(linked&&record.amount>invoice.balance)throw new Error('Collections in this batch exceed the unpaid invoice balance.');
                    }
                    if(sheet==='payments'){const error=paymentError(record,[...state.payments,record]);if(error)throw new Error(error);}
                    if(sheet==='cwt'){
                        const sale=state.sales.find(item=>item.id===record.saleId&&item.status==='posted'),source=window.KontaduryaLedger.cwtSource(record.saleId);
                        if(!sale||source.withheld<=0||record.invoice!==sale.number||record.customer!==sale.customer||record.taxBase!==source.taxBase||record.amount!==source.withheld||record.atc!==sale.cwt)throw new Error('Select a posted sale with matching income withholding.');
                        if(!/^\d{3}-?\d{3}-?\d{3}(?:-?\d{3,5})?$/.test(record.tin || ''))throw new Error('Enter the customer TIN.');
                        if(state.cwtCertificates.some(item=>item.status==='posted'&&(item.saleId===record.saleId||String(item.certificate).toLowerCase()===record.certificate.toLowerCase())))throw new Error('The source invoice or certificate number already has a posted CWT certificate.');
                    }
                    if(sheet==='wtp'){const error=wtpError(record,[...state.wtpCertificates,record]);if(error)throw new Error(error);}
                    if(sheet==='journal'){const error=journalError(record);if(error)throw new Error(error);}
                    state[key].push(record);seen.add(signature);imported.push(record);
                }catch(problem){errors.push({row:input._row || 0,message:problem.message});}
            }
            if(commit&&!errors.length){state.nextCollectionNumber=nextCollectionNumber();syncCashCollections();syncCashPayments();persist();return {count:imported.length,records:clone(imported),errors:[]};}
            state=previous;
            if(commit)throw new Error(errors.map(error=>'Row '+error.row+': '+error.message).join('\n'));
            return {count:imported.length,records:clone(imported),errors};
        }catch(error){state=previous;throw error;}
    }
    window.KontaduryaLedger = {
        importFingerprint,
        previewImport:(sheet,records)=>processImport(sheet,records,false,'Import preview'),
        postImport:(sheet,records,user='Demo user')=>processImport(sheet,records,true,user),
        bulkTransactions(sheet,ids,action,reason='',user='Demo user') {
            const key={sales:'sales',collections:'collections',purchases:'purchases',payments:'payments',cwt:'cwtCertificates',wtp:'wtpCertificates',journal:'journalEntries'}[sheet];
            if(!key||!['void','delete-drafts','delete-voids','undo-delete-voids'].includes(action))throw new Error('Choose a valid bulk action.');
            const status={void:'posted','delete-drafts':'draft','delete-voids':'void','undo-delete-voids':'archived'}[action],selected=new Set(ids.map(String));
            const targets=state[key].filter(record=>selected.has(String(record.id))&&record.status===status);
            if(!targets.length)throw new Error('No selected entries match this action.');
            if(action==='void'&&!reason.trim())throw new Error('Enter a reason for voiding the selected entries.');
            const previous=state,time=new Date().toISOString();state=clone(state);
            try{
                if(action==='delete-drafts')state[key]=state[key].filter(record=>!targets.some(target=>target.id===record.id));
                else for(const record of state[key].filter(record=>targets.some(target=>target.id===record.id))){
                    if(action==='void'){
                        record.previousStatus=record.status;record.status='void';record.voidReason=reason.trim();record.voidedAt=time;record.voidedBy=user;
                        record.voidAudit={reason:reason.trim(),user,time};record.voidHistory=[...(record.voidHistory || []),clone(record.voidAudit)];
                        if(sheet==='collections')record.collectionVoided=true;
                        if(sheet==='payments')record.paymentVoided=true;
                    }else{
                        record.status=action==='delete-voids'?'archived':'void';record.archivedAt=time;
                        if(sheet==='collections'){record.collectionArchived=action==='delete-voids';record.collectionVoided=true;}
                        if(sheet==='payments'){record.paymentArchived=action==='delete-voids';record.paymentVoided=true;}
                    }
                    record.history=[...(record.history || []),{label:action==='void'?'Voided':action==='delete-voids'?'Archived':'Unarchived',user,time}];
                }
                if(action==='delete-drafts')compactAfterRemoval(key,previous);
                state.nextCollectionNumber=nextCollectionNumber();syncCashCollections();syncCashPayments();persist();
            }catch(error){state=previous;throw error;}
            return {count:targets.length,ids:targets.map(record=>record.id)};
        },
        bookEntries,
        accountProfile,
        taxAmounts:(gross,tax)=>window.SheetMasters?window.SheetMasters.taxAmounts(gross,tax):{net:['12% VAT','VAT (12%)'].includes(tax)?Math.round(gross*100/112):gross,tax:{kind:['12% VAT','VAT (12%)'].includes(tax)?'VAT':tax.includes('Zero')?'Zero Rated':'Exempt',code:tax==='Non-VAT'?'NONVAT':tax.includes('Zero')?'ZERO':'EXEMPT'}},
        setAccountSelect,
        journalEntries:()=>clone(state.journalEntries),
        journalAccounts:()=>[...journalAccounts],
        journalAmounts,
        journalError,
        nextJournalEntry(){
            const id=state.journalEntries.reduce((max,record)=>Math.max(max,Number(record.id) || 0),0)+1;
            const suffix=state.journalEntries.reduce((max,record)=>Math.max(max,Number(String(record.number).split('-')[1]) || 0),0)+1;
            return {id,number:'GJ-'+String(suffix).padStart(6,'0')};
        },
        saveJournalEntries(entries){
            const next=clone(entries),ids=new Set(),numbers=new Set(),remittedPayrolls=new Set();
            for(const record of next){
                if(ids.has(record.id)||numbers.has(record.number))throw new Error('Duplicate journal transaction.');
                ids.add(record.id);numbers.add(record.number);
                if(record.origin==='payroll-remittance'&&record.status==='posted'){
                    const allocations=record.payrollAllocations || [{runId:record.payrollRunId,amounts:{salary:1,sss:1,phic:1,hdmf:1,withholding:1}}];
                    for(const allocation of allocations){
                        if(!allocation.runId)throw new Error('A payroll remittance must link its source run.');
                        for(const key of Object.keys(allocation.amounts)){
                            const pair=JSON.stringify([allocation.runId,key]);
                            if(remittedPayrolls.has(pair))throw new Error('This payroll liability already has a posted remittance journal. Void the previous remittance first.');
                            remittedPayrolls.add(pair);
                        }
                    }
                    const original=state.journalEntries.find(item=>item.id===record.id&&item.origin==='payroll-remittance'&&item.status!=='draft');
                    if(original&&(original.date!==record.date||JSON.stringify(original.lines)!==JSON.stringify(record.lines)||JSON.stringify(original.payrollAllocations)!==JSON.stringify(record.payrollAllocations)))throw new Error('Void this generated payroll remittance and generate a replacement from Payroll to change its date or amounts.');
                }
                const originalRemittance=state.journalEntries.find(item=>item.id===record.id&&item.origin==='payroll-remittance');
                if(originalRemittance&&(record.origin!==originalRemittance.origin||(originalRemittance.status!=='draft'&&record.payrollRunId!==originalRemittance.payrollRunId)))throw new Error('The payroll remittance link cannot be removed.');
                if(record.status==='posted'){const error=journalError(record);if(error)throw new Error(record.number+': '+error);}
                if(record.status==='void'&&!record.voidReason?.trim())throw new Error('A reason is required to void a journal entry.');
            }
            const previous=state;state={...state,journalEntries:next};
            try{compactAfterRemoval('journalEntries',previous);persist();synchronizeNumbers('journalEntries',entries);}catch(error){state=previous;throw error;}
        },
        wtpCertificates:()=>clone(state.wtpCertificates),
        wtpAmounts,
        wtpSource,
        wtpAtc,
        wtpVatAtc,
        wtpError,
        certificateNumber,
        nextWtpCertificate() {
            const id=state.wtpCertificates.reduce((highest,record)=>Math.max(highest,Number(record.id) || 0),0)+1;
            const suffix=state.wtpCertificates.reduce((highest,record)=>Math.max(highest,Number(String(record.number).split('-')[1]) || 0),0)+1;
            return {id,number:`2307I-${String(suffix).padStart(6,'0')}`};
        },
        saveWtpCertificates(certificates) {
            const next=clone(certificates);
            for(const record of next.filter(record=>record.status==='posted'))record.certificate=certificateNumber(record,next);
            for(const record of next.filter(record=>record.status==='posted')){
                const previous=state.wtpCertificates.find(item=>item.id===record.id&&item.status==='posted');
                const sameSource=previous&&['billId','bill','supplier','taxBase','ewt','wvat','offset'].every(key=>previous[key]===record[key]);
                const error=wtpError(record,next,!sameSource);if(error)throw new Error(error);
            }
            const previous=state;state={...state,wtpCertificates:next};
            try{compactAfterRemoval('wtpCertificates',previous);persist();synchronizeNumbers('wtpCertificates',certificates);}catch(error){state=previous;throw error;}
        },
        payments:()=>clone(state.payments),
        payableBills,
        paymentError,
        nextPayment,
        savePayments(payments) {
            const previous=clone(state);
            state.payments=clone(payments).map(record=>window.SheetMasters?window.SheetMasters.partySnapshot(record,previous.payments.find(item=>item.id===record.id)):record);
            try {
                for(const record of state.payments.filter(record=>record.status==='posted')){const error=paymentError(record);if(error)throw new Error(error);}
                compactAfterRemoval('payments',previous);persist();synchronizeNumbers('payments',payments);
            }catch(error){state=previous;throw error;}
        },
        purchases:()=>clone(state.purchases),
        purchaseAmounts,
        savePurchases(purchases) {
            const previous=clone(state);
            state={...state,purchases:clone(purchases).map(record=>window.SheetMasters?window.SheetMasters.partySnapshot(record,previous.purchases.find(item=>item.id===record.id)):record)};
            try {
                compactAfterRemoval('purchases',previous);syncCashPayments();
                for(const record of state.payments.filter(record=>record.status==='posted')){const error=paymentError(record);if(error)throw new Error(error);}
                persist();synchronizeNumbers('purchases',purchases);
            } catch(error){state=previous;throw error;}
        },
        hasSales:()=>state.sales.length>0,
        sales:()=>clone(state.sales),
        collections:()=>clone(state.collections),
        invoices,
        cwtCertificates:()=>clone(state.cwtCertificates),
        receivedCwtForSale(saleId){const sale=state.sales.find(record=>String(record.id)===String(saleId));if(!sale)return [];return clone(state.cwtCertificates.filter(record=>record.status==='posted'&&(record.invoice===sale.number||String(record.saleId)===String(sale.id))));},
        cwtSource(saleId) {
            const sale=state.sales.find(record=>saleId!==null&&saleId!==undefined&&saleId!==''&&String(record.id)===String(saleId)&&record.status==='posted');
            if(!sale)return null;
            const taxBase=window.SheetMasters?window.SheetMasters.taxAmounts(sale.gross,sale.tax).net:sale.tax==='12% VAT'?Math.round(sale.gross*100/112):sale.gross;
            const rate=Number(String(sale.cwt || '').match(/(\d+(?:\.\d+)?)%/)?.[1] || 0);
            return {...clone(sale),tin:sale.customerTin || sale.tin || '',taxBase,rate,withheld:Math.round(taxBase*rate/100)};
        },
        saveCwtCertificates(certificates) {
            const previous=state;
            state={...state,cwtCertificates:clone(certificates)};
            for(const record of state.cwtCertificates.filter(record=>record.status==='posted'))record.certificate=certificateNumber(record,state.cwtCertificates);
            try {compactAfterRemoval('cwtCertificates',previous);persist();synchronizeNumbers('cwtCertificates',certificates);} catch(error){state=previous;throw error;}
        },
        saveSales(sales) {
            const previous=state;
            state={...state,sales:normalizeTerms(clone(sales)).map(record=>window.SheetMasters?window.SheetMasters.partySnapshot(record,previous.sales.find(item=>item.id===record.id)):record),collections:clone(state.collections)};
            compactAfterRemoval('sales',previous);syncCashCollections();
            try {persist();synchronizeNumbers('sales',sales);} catch(error){state=previous;throw error;}
        },
        saveCollections(collections) {
            const previous=state;
            state={...state,collections:clone(collections).map(record=>window.SheetMasters?window.SheetMasters.partySnapshot(record,previous.collections.find(item=>item.id===record.id)):record)};
            compactAfterRemoval('collections',previous);
            state.nextCollectionNumber=nextCollectionNumber();
            syncCashCollections();
            try {persist();synchronizeNumbers('collections',collections);} catch(error){state=previous;throw error;}
        },
        newCollection() {const result=nextCollection();persist();return result;},
        voidCollection(id,reason,user) {
            const record=state.collections.find(collection=>collection.id===id);
            const message=String(reason || '').trim();
            if(!record||!['posted','draft'].includes(record.status))throw new Error('This collection is no longer available to void.');
            if(!message||message.length>1000)throw new Error('Enter a void reason of up to 1,000 characters.');
            const previous=clone(state);
            record.previousStatus=record.status;
            record.collectionVoided=true;
            record.status='void';
            record.voidAudit={reason:message,user:user || 'Demo user',time:new Date().toISOString()};
            record.voidHistory=[...(record.voidHistory || []),clone(record.voidAudit)];
            try {persist();} catch(error){state=previous;throw error;}
            return clone(record);
        },
        restoreCollection(id) {
            const record=state.collections.find(collection=>collection.id===id);
            if(!record||record.status!=='void')throw new Error('Only a voided collection can be restored.');
            if(record.origin!=='cash-sale'&&state.collections.some(item=>item.id!==record.id&&item.status==='draft'))throw new Error('Only one draft is allowed on this page. Post or delete the existing draft before restoring this collection.');
            const previous=clone(state);
            if(record.origin==='cash-sale') {
                const invoice=invoices().find(sale=>sale.id===record.saleId);
                if(!invoice||invoice.terms!=='Cash')throw new Error('Restore the linked cash sale in Sales before restoring this receipt.');
                if(record.amount>invoice.balance)throw new Error('This invoice has other collections. Void the replacement receipt before restoring this one.');
                record.status='posted';
            } else record.status='draft';
            record.collectionVoided=false;
            record.restoredAt=new Date().toISOString();
            try {persist();} catch(error){state=previous;throw error;}
            return clone(record);
        },
        editCollectionVoidMemo(id,reason,user) {
            return updateCollection(id,record=>{
                if(record.status!=='void')throw new Error('Only a voided collection memo can be edited.');
                const message=String(reason || '').trim();
                if(!message||message.length>1000)throw new Error('Enter a memo of up to 1,000 characters.');
                const time=new Date().toISOString();
                record.memoHistory=[...(record.memoHistory || []),{reason:record.voidAudit?.reason || '',user:user || 'Demo user',time}];
                record.voidAudit={...record.voidAudit,reason:message,editedBy:user || 'Demo user',editedAt:time};
            });
        },
        archiveVoidedCollection(id) {
            return updateCollection(id,record=>{
                if(record.status!=='void')throw new Error('Only a voided collection can be purged.');
                record.collectionArchived=true;record.status='archived';record.archivedAt=new Date().toISOString();
            });
        },
        unarchiveVoidedCollection(id) {
            return updateCollection(id,record=>{
                if(record.status!=='archived'||!record.collectionArchived)throw new Error('This collection is not in the local archive.');
                record.collectionArchived=false;record.collectionVoided=true;record.status='void';
            });
        },
        receivable
    };
    window.addEventListener('pageshow',event=>{if(event.persisted)location.reload();});
})();
