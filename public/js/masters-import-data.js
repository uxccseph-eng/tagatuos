(function(root){
    const key=value=>String(value??'').trim().toLowerCase().replace(/[^a-z0-9]/g,'');
    const aliases={
        code:['account code','party code','employee code','tax code','atc code'],
        name:['name','registered name','company name','supplier name','customer name','employee name','account name','description name'],
        type:['account type'],classification:['party classification','customer supplier','employment class'],
        entity_type:['entity type','party type'],is_petty_cash:['petty cash','petty cash payee'],
        tin:['tax identification number','supplier tin','customer tin','employee tin','tax id'],
        last_name:['surname','last name'],first_name:['given name','first name'],middle_name:['middle name'],
        email:['email address'],phone:['phone number','contact number','mobile'],address:['registered address','full address'],
        city:['city municipality','municipality'],province:['state province'],zip_code:['zip','postal code','postcode'],
        gl_account:['gl account','general ledger account','cash account'],account_number:['bank account number'],
        bank:['bank name'],rate:['tax rate','rate percent'],kind:['tax kind'],normal_balance:['normal balance'],
        statement_section:['financial statement section'],birth_date:['date of birth','birthday'],hire_date:['date hired'],
    };
    function guessMapping(headers,columns,registry){
        const mapping={},used=new Set();
        for(const column of columns){
            const names=[column,...(aliases[column]||[])].map(key);
            if(column==='name'&&registry==='atc-codes')names.push('description','incomedescription');
            const matches=headers.map((header,index)=>({header:key(header),index})).filter(item=>item.header&&names.includes(item.header));
            const match=matches.length===1&&!used.has(matches[0].index)?matches[0].index:null;
            mapping[column]=match;if(match!==null)used.add(match);
        }
        return mapping;
    }
    function source(matrix,headerRow=1){
        if(!Number.isInteger(headerRow)||headerRow<1||headerRow>20||!Array.isArray(matrix)||headerRow>=matrix.length)throw Error('Choose a header row followed by at least one data row.');
        if(matrix.some(row=>!Array.isArray(row)))throw Error('Source data must contain spreadsheet rows.');
        if(matrix.some(row=>row.some(value=>String(value??'').includes('\u0000'))))throw Error('Source cells contain unsupported binary characters.');
        const headers=matrix[headerRow-1].map(value=>String(value??'').trim());
        if(!headers.length||headers.length>100||!headers.some(Boolean))throw Error('Use between 1 and 100 source columns with a header.');
        const rows=[];
        matrix.slice(headerRow).forEach((values,index)=>{
            if(!values.some(value=>String(value??'').trim()))return;
            if(values.slice(headers.length).some(value=>String(value??'').trim()))throw Error('Source row '+(headerRow+index+1)+' has values beyond the header columns.');
            if(values.some(value=>String(value??'').length>4000))throw Error('Source cells must contain at most 4,000 characters.');
            rows.push({sourceRow:headerRow+index+1,values:headers.map((_,i)=>String(values[i]??''))});
        });
        if(!rows.length||rows.length>500)throw Error('Import between 1 and 500 nonempty data rows.');
        return {headers,rows};
    }
    function mapRows(source,mapping,columns){
        const indices=columns.map(column=>mapping[column]).filter(value=>value!==null&&value!==undefined&&value!=='');
        if(!indices.length)throw Error('Map at least one source column.');
        if(new Set(indices).size!==indices.length)throw Error('A source column cannot be mapped to multiple Masters fields.');
        if(indices.some(index=>!Number.isInteger(index)||index<0||index>=source.headers.length))throw Error('Select a valid source column.');
        return source.rows.map(row=>({sourceRow:row.sourceRow,included:true,record:Object.fromEntries(columns.map(column=>[column,mapping[column]==null||mapping[column]===''?'':row.values[mapping[column]].trim()]))}));
    }
    function included(rows){return rows.filter(row=>row.included);}
    function fillBlanks(rows,column,value){
        let changed=0;
        for(const row of rows)if(row.included&&!String(row.record[column]??'').trim()){row.record[column]=String(value).trim();changed++;}
        return changed;
    }
    function csv(rows){return '\uFEFF'+rows.map(row=>row.map(value=>'"'+String(value??'').replace(/^[=+@\-\t\r]/,"'$&").replace(/"/g,'""')+'"').join(',')).join('\r\n');}
    function sampleRows(registry){
        const samples={
            'chart-of-accounts':[{code:'',name:'SAMPLE Internet Expense - REPLACE',type:'Expense',normal_balance:'Debit',statement_section:'General and Administrative Expenses',description:'SAMPLE ONLY - replace or remove before importing',is_cash_bank:'No',is_advance:'No',is_control:'No'}],
            parties:[{code:'',name:'SAMPLE Supplier Corporation - REPLACE',entity_type:'corporation',classification:'Supplier',is_petty_cash:'No',tin:'REPLACE WITH ACTUAL SUPPLIER TIN',email:'supplier@example.com',phone:'',building:'',street:'',barangay:'Sample Barangay',city:'Quezon City',province:'Metro Manila',zip_code:'1100',address:'',description:'SAMPLE ONLY - regular supplier; street optional; use the actual registered address'},
                {code:'',name:'',entity_type:'individual',last_name:'DELA CRUZ',first_name:'JUAN',middle_name:'SANTOS',classification:'Supplier',is_petty_cash:'No',tin:'REPLACE WITH ACTUAL SUPPLIER TIN',city:'Quezon City',province:'Metro Manila',description:'SAMPLE ONLY - individual supplier; first and last names required; replace all details'},
                {code:'',name:'SAMPLE Petty Cash Payee - REPLACE',entity_type:'corporation',classification:'Supplier',is_petty_cash:'Yes',tin:'',description:'SAMPLE ONLY - petty cash supplier; TIN and address optional'}],
            employees:[{code:'',name:'SAMPLE Employee - REPLACE',classification:'Regular',tin:'',email:'employee@example.com',phone:'',address:'Replace with actual employee address',birth_date:'1995-01-15',hire_date:'2026-10-01',end_date:'',sss_no:'',philhealth_no:'',pagibig_no:''}],
            'bank-accounts':[{name:'SAMPLE Operating Bank - REPLACE',bank:'SAMPLE Bank - REPLACE',account_number:'001234567890',gl_account:'REPLACE WITH ACTIVE CASH OR BANK ACCOUNT CODE',currency:'PHP'}],
            'tax-codes':[{code:'',name:'SAMPLE VAT 12% - REPLACE',kind:'VAT',rate:'12',atc:''}],
            'atc-codes':[{code:'REPLACE WITH ACTUAL CUSTOM ATC',name:'SAMPLE Custom Withholding - REPLACE',rate:'1'}]
        };
        if(!samples[registry])throw Error('Unknown Masters registry.');
        return samples[registry];
    }
    function template(registry,columns,withSamples=false){return csv([columns,...(withSamples?sampleRows(registry).map(row=>columns.map(column=>row[column]??'')):[])])+'\r\n';}
    const api={guessMapping,source,mapRows,included,fillBlanks,csv,sampleRows,template};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;
    if(root)root.MasterImportData=api;
})(typeof window!=='undefined'?window:null);
