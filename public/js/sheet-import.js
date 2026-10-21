(function(root){
    const columns={
        sales:['Date','Customer','Gross Amount','Tax Type','Revenue Account','Income Withholding ATC','VAT Withholding ATC','Terms','Invoice Reference','Classification','Deposit Account','Description','Particulars'],
        purchases:['Date','Supplier','Supplier TIN','Gross Amount','Tax Type','Account','Income Withholding ATC','VAT Withholding ATC','Terms','Invoice Reference','Bank','Description','Notes'],
        collections:['Date','Customer','Source Invoice','Amount','Bank','Reference','Payment Method','Entry Type','Notes'],
        payments:['Date','Supplier','Supplier TIN','Source Bill','Amount','Bank','Payment Method','Reference','Entry Type','Account','Notes'],
        cwt:['Date','Source Invoice','Customer TIN','Certificate Number','Notes'],
        wtp:['Date','Source Bill','Supplier','Supplier TIN','Tax Base','Income Withholding ATC','VAT Withholding ATC','Offset Account','Certificate Number','Notes'],
        journal:['Voucher Key','Date','Reference','Particulars','Entry Type','Account','Party','Debit','Credit','Remarks','Notes']
    };
    const methods={sales:'sales',purchases:'purchases',collections:'collections',payments:'payments',cwt:'cwtCertificates',wtp:'wtpCertificates',journal:'journalEntries'};
    const key=value=>String(value || '').trim().toLowerCase().replace(/[^a-z0-9]/g,'');
    function parseCsv(text){
        text=String(text).replace(/^\uFEFF/,'');const rows=[];let row=[],cell='',quoted=false,closed=false;
        for(let i=0;i<text.length;i++){
            const char=text[i];
            if(quoted){if(char==='"'){if(text[i+1]==='"'){cell+='"';i++;}else{quoted=false;closed=true;}}else cell+=char;continue;}
            if(char==='"'){if(cell||closed)throw Error('Unexpected quote in CSV. Use the downloaded template and save as CSV UTF-8.');quoted=true;}
            else if(char===','||char==='\n'||char==='\r'){row.push(cell);cell='';closed=false;if(char!==','){if(char==='\r'&&text[i+1]==='\n')i++;if(row.some(value=>value.trim()))rows.push(row);row=[];}}
            else {if(closed&&!/\s/.test(char))throw Error('Unexpected text after a quoted CSV field.');if(!closed)cell+=char;}
        }
        if(quoted)throw Error('The CSV contains an unclosed quoted field.');row.push(cell);if(row.some(value=>value.trim()))rows.push(row);return rows;
    }
    function template(sheet){if(!columns[sheet])throw Error('Unknown sheet.');return '\uFEFF'+columns[sheet].map(value=>'"'+value+'"').join(',')+'\r\n';}
    function amount(value,label='Amount',zero=false){const text=String(value || '').replace(/[₱,\s]/g,'');if(!text&&zero)return 0;if(!/^\d{1,9}(\.\d{1,2})?$/.test(text))throw Error(label+' must be a number with no more than two decimal places.');const [whole,fraction='']=text.split('.');const cents=Number(whole)*100+Number(fraction.padEnd(2,'0'));if(!zero&&cents<=0)throw Error(label+' must be greater than zero.');return cents;}
    function date(value){let text=String(value || '').trim();const match=text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);if(match)text=match[3]+'-'+match[1].padStart(2,'0')+'-'+match[2].padStart(2,'0');const parsed=new Date(text+'T12:00:00Z');if(!/^\d{4}-\d{2}-\d{2}$/.test(text)||!Number.isFinite(parsed.getTime())||parsed.toISOString().slice(0,10)!==text)throw Error('Date must be a real date in YYYY-MM-DD or MM/DD/YYYY format.');return text;}
    function fingerprint(sheet,record){const fields={sales:['date','customer','gross','reference','account','tax','terms','cwt','vatCwt'],purchases:['date','supplier','gross','reference','account','tax','terms','ewt','wvat'],collections:['date','customer','invoice','amount','reference','type'],payments:['date','supplier','bill','amount','reference','type','account'],cwt:['date','invoice','customer','tin','taxBase','atc','amount'],wtp:['date','bill','supplier','tin','taxBase','ewt','wvat','ewtCode','wvatCode','offset'],journal:['date','reference','type','lines']}[sheet];return JSON.stringify(fields.map(field=>{const value=record[field];return typeof value==='string'?value.trim().toLowerCase():value ?? '';}));}
    function build(sheet,raw,context){
        const {ledger,masters,accounts}=context;
        const get=name=>raw[key(name)] || '',find=(items,value,label,predicate=()=>true)=>{const normalized=key(value);const matches=items.filter(item=>predicate(item)&&[item.code,item.name,item.value,item.label,item.number,item.code&&item.name?item.code+' · '+item.name:''].some(text=>text&&key(text)===normalized));if(!normalized||matches.length!==1)throw Error(label+' must match one active record in Masters.');return matches[0];};
        const account=(value,types=null,cash=false)=>{const item=find(accounts.filter(item=>!item.archived),value,'Account',item=>(!types||types.includes(item.type))&&(!cash||item.is_cash_bank));return item.code+' · '+item.name;};
        const bank=value=>{if(!value)return account(accounts.find(item=>!item.archived&&item.is_cash_bank&&item.code==='1010')?.code || accounts.find(item=>!item.archived&&item.is_cash_bank)?.code,null,true);const named=masters.bankChoices().filter(item=>key(item.value)===key(value)||key(item.label)===key(value));return named.length===1?named[0].value:account(value,null,true);};
        const party=(value,kind)=>find(masters.parties(kind),value,kind),tax=(value,sales)=>find(masters.taxChoices(sales),value || 'VAT','Tax type').value;
        const sourceParty=(value,source,kind)=>source&&(!value||key(value)===key(source.customer || source.supplier))?{name:source.customer || source.supplier,tin:source.tin}:party(value,kind);
        const atc=(value,vat=false)=>{if(!value||['none','0','0%'].includes(value.toLowerCase()))return {rate:0,label:'None',code:''};const item=find(masters.atcCodes(vat?'VAT Withholding':'Income-tax CWT'),value,'Withholding ATC');return {rate:Number(item.rate),label:item.code+' · '+Number(item.rate)+'%',code:item.code};};
        const choice=(value,values,fallback)=>{const selected=values.find(item=>key(item)===key(value || fallback));if(!selected)throw Error('Choose '+values.join(', ')+'.');return selected;};
        const record={date:date(get('Date')),notes:get('Notes'),_row:raw._row};
        if(sheet==='sales'||sheet==='purchases'){
            const sales=sheet==='sales',person=party(get(sales?'Customer':'Supplier'),sales?'Customer':'Supplier'),it=atc(get('Income Withholding ATC')),vt=atc(get('VAT Withholding ATC'),true);
            Object.assign(record,{[sales?'customer':'supplier']:person.name,tin:masters.formatTin(get('Supplier TIN') || person.tin || ''),partyId:person.id,gross:amount(get('Gross Amount'),'Gross amount'),tax:tax(get('Tax Type'),sales),account:account(get(sales?'Revenue Account':'Account'),sales?['Income']:['Asset','Expense']),terms:choice(get('Terms'),['Cash','On Account'],'Cash'),reference:get('Invoice Reference'),classification:choice(get('Classification'),['Regular','Top Withholding Agent','Government','ZeroRated','PEZA'],'Regular'),bank:bank(get(sales?'Deposit Account':'Bank'))});
            if(sales)Object.assign(record,{cwt:it.label,vatCwt:vt.label,memo:get('Description'),notes:get('Particulars')});else Object.assign(record,{ewt:it.rate,wvat:vt.rate,ewtCode:it.code,wvatCode:vt.code,description:get('Description')});
        }else if(sheet==='collections'){
            const invoice=get('Source Invoice')?find(ledger.invoices(),get('Source Invoice'),'Source invoice'):null;
            const person=sourceParty(get('Customer'),invoice,'Customer');Object.assign(record,{customer:person.name,invoice:invoice?.number || 'Unlinked / Advance Deposit',saleId:invoice?.id || null,amount:amount(get('Amount')),bank:bank(get('Bank') || invoice?.bank),reference:get('Reference'),method:choice(get('Payment Method'),['Cash','Check','Bank Transfer','GCash','Maya','Other'],'Cash'),type:choice(get('Entry Type'),['Settlement','Downpayment','Advance'],invoice?'Settlement':'Advance'),origin:'manual'});
        }else if(sheet==='payments'){
            const bill=get('Source Bill')?find(ledger.payableBills(),get('Source Bill'),'Source bill'):null,person=sourceParty(get('Supplier'),bill,'Supplier');Object.assign(record,{supplier:person.name,tin:masters.formatTin(get('Supplier TIN') || bill?.tin || person.tin || ''),billId:bill?.id || null,bill:bill?.number || '',amount:amount(get('Amount')),bank:bank(get('Bank') || bill?.bank),reference:get('Reference'),method:choice(get('Payment Method'),['Cash','Check','Bank Transfer','GCash','Maya','Other'],'Cash'),type:choice(get('Entry Type'),['AP Settlement','Direct Expense','Supplier Advance'],bill?'AP Settlement':'Direct Expense'),account:bill?account('2010'):get('Account')?account(get('Account'),['Asset','Expense']):'',origin:'manual'});
        }else if(sheet==='cwt'){
            const sale=find(ledger.sales().filter(item=>item.status==='posted'),get('Source Invoice'),'Source invoice'),source=ledger.cwtSource(sale.id);Object.assign(record,{saleId:sale.id,invoice:sale.number,customer:sale.customer,tin:masters.formatTin(get('Customer TIN') || sale.tin || ''),taxBase:source.taxBase,atc:sale.cwt,amount:source.withheld,offset:account('1150'),certificate:get('Certificate Number')});
        }else if(sheet==='wtp'){
            const bill=get('Source Bill')?find(ledger.purchases().filter(item=>item.status==='posted'),get('Source Bill'),'Source bill'):null,source=bill?ledger.wtpSource(bill.id):null,person=sourceParty(get('Supplier'),bill,'Supplier'),it=get('Income Withholding ATC')?atc(get('Income Withholding ATC')):{rate:source?.ewt || 0,code:source?.ewtCode || ''},vt=get('VAT Withholding ATC')?atc(get('VAT Withholding ATC'),true):{rate:source?.wvat || 0,code:source?.wvatCode || ''};
            Object.assign(record,{billId:bill?.id || null,bill:bill?.number || '',supplier:person.name,tin:masters.formatTin(get('Supplier TIN') || bill?.tin || person.tin || ''),taxBase:get('Tax Base')?amount(get('Tax Base'),'Tax base'):source?.taxBase || 0,ewt:it.rate,wvat:vt.rate,ewtCode:it.code,wvatCode:vt.code,offset:account(get('Offset Account') || '2010'),certificate:get('Certificate Number')});
        }else if(sheet==='journal'){
            Object.assign(record,{voucherKey:get('Voucher Key'),reference:get('Reference'),particulars:get('Particulars'),type:choice(get('Entry Type'),['General','Adjusting','Opening','Closing','Reclassification'],'General'),lines:[{account:account(get('Account')),party:get('Party')?party(get('Party'),null).name:'',debit:amount(get('Debit'),'Debit',true),credit:amount(get('Credit'),'Credit',true),remarks:get('Remarks')}]});if(!record.voucherKey)throw Error('Voucher Key is required to group the journal lines.');
        }
        return record;
    }
    function preview(sheet,text,context){
        if(!columns[sheet])throw Error('Unknown sheet.');if(text.length>5*1024*1024)throw Error('Use a CSV file smaller than 5 MB.');
        const rows=parseCsv(text);if(rows.length<2)throw Error('Add entry rows below the template header.');if(rows.length>501)throw Error('Import up to 500 rows at a time.');
        const headers=rows.shift().map(key),allowed=columns[sheet].map(key);if(headers.some(header=>!allowed.includes(header))||new Set(headers).size!==headers.length)throw Error('The file has unknown or duplicate columns. Use this page’s template.');
        const errors=[],records=[],groups=new Map(),seen=new Set(context.ledger[methods[sheet]]().filter(record=>['posted','draft'].includes(record.status)).map(record=>fingerprint(sheet,record)));
        rows.forEach((values,index)=>{const row=index+2;try{if(values.length!==headers.length)throw Error('Column count does not match the header. Quote values containing commas.');const raw={...Object.fromEntries(headers.map((header,i)=>[header,values[i].trim()])),_row:row};let record=build(sheet,raw,context);
            if(sheet==='journal'){const previous=groups.get(record.voucherKey);if(previous){if(['date','reference','particulars','type','notes'].some(field=>previous[field]!==record[field]))throw Error('Rows with the same Voucher Key must have matching voucher details.');previous.lines.push(...record.lines);return;}groups.set(record.voucherKey,record);}
            records.push(record);
        }catch(problem){errors.push({row,message:problem.message});}});
        for(const record of records){const signature=fingerprint(sheet,record);if(seen.has(signature))errors.push({row:record._row,message:'Duplicate entry: already in this file or the current sheet.'});seen.add(signature);}
        if(!errors.length){const validation=context.ledger.previewImport(sheet,records);errors.push(...validation.errors);}
        return {records,errors,rowCount:rows.length};
    }
    const api={columns,methods,parseCsv,template,amount,date,fingerprint,preview};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(root)root.SheetImport=api;
})(typeof window==='undefined'?null:window);
