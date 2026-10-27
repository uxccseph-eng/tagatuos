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
    const api={guessMapping,source,mapRows,included,fillBlanks,csv};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;
    if(root)root.MasterImportData=api;
})(typeof window!=='undefined'?window:null);
