(function(root){
    'use strict';
    const payroll=typeof module!=='undefined'&&module.exports?require('./payroll-register.js'):root.PayrollRegister;
    const automation=typeof module!=='undefined'&&module.exports?require('./payroll-automation.js'):root.PayrollAutomation;
    const importer=typeof module!=='undefined'&&module.exports?require('./sheet-import.js'):root.SheetImport;
    const requiredHeaders=['pay_date','period_start','period_end','basic','withholding'];
    const simpleHeaders=['pay_date','period_start','period_end','employee','pay_type','basic','kpiIncentive','commission','withholding'];
    const headers=[...payroll.importHeaders,...payroll.extraFields,'employee_code','taxable_benefits','review_basis','monthlyBasic','monthlyCompensation','monthlyFundSalary','contribution_share'];
    function template(withSamples=false,detailed=false){
        const outputHeaders=detailed?headers:simpleHeaders;
        const sample={...Object.fromEntries(payroll.extraFields.map(field=>[field,'0.00'])),pay_date:'2026-10-31',run_name:'SAMPLE Monthly Payroll - REPLACE',period_start:'2026-10-01',period_end:'2026-10-31',employee:'REPLACE WITH EMPLOYEE NAME',employee_code:'REPLACE WITH EMPLOYEE CODE',pay_type:'Taxable',basic:'20000.00',taxableAllowance:'0.00',deMinimis:'0.00',benefits:'0.00',sssEe:'',phicEe:'',hdmfEe:'',withholding:'0.00',sssEr:'',phicEr:'',hdmfEr:'',taxable_benefits:'0.00',review_basis:'regular'};
        return '\uFEFF'+[outputHeaders,...(withSamples?[outputHeaders.map(field=>sample[field]??'')]:[])].map(row=>row.map(value=>'"'+String(value).replace(/"/g,'""')+'"').join(',')).join('\r\n')+'\r\n';
    }
    const normalize=value=>String(value??'').trim().replace(/\s+/g,' ').toLowerCase();
    const headerKey=value=>normalize(value).replace(/[^a-z0-9]/g,'');
    const money=value=>(value/100).toFixed(2);
    function amountText(value){
        let text=String(value??'').trim().replace(/^(?:PHP\s*|₱\s*)/i,'');if(!text)return '';
        if(text.includes(',')&&!/^\d{1,3}(?:,\d{3})+(?:\.\d{0,2})?$/.test(text))throw Error('Use commas only as thousands separators in payroll amounts.');
        return money(payroll.cents(text.replaceAll(',','')));
    }
    const aliases={pay_date:['pay date','payment date'],run_name:['run name','payroll name'],period_start:['period start','start date'],period_end:['period end','end date'],employee:['employee name','full name','name'],employee_code:['employee code','employee id','staff code'],pay_type:['pay type','tax classification'],basic:['basic salary','basic pay'],taxableAllowance:['taxable allowance','taxable allowances','taxable allow'],deMinimis:['de minimis','de minimis benefits'],benefits:['benefits','total benefits','13th & other benefits'],sssEe:['sss employee','employee sss'],phicEe:['philhealth employee','employee philhealth'],hdmfEe:['pag ibig employee','employee pag ibig'],withholding:['tax deducted','withholding tax','tax withheld','wtax','wtax (actual)'],sssEr:['sss employer','employer sss'],phicEr:['philhealth employer','employer philhealth'],hdmfEr:['pag ibig employer','employer pag ibig'],taxable_benefits:['taxable benefits'],review_basis:['review basis']};
    Object.assign(aliases,{monthlyBasic:['monthly basic','monthly philhealth basic'],monthlyCompensation:['monthly sss compensation'],monthlyFundSalary:['monthly pag ibig fund salary','monthly hdmf fund salary'],contribution_share:['monthly contribution share']});
    for(const [field,definition] of Object.entries(payroll.extraDefinitions))aliases[field]=[definition.label];
    aliases.kpiIncentive=['KPI','KPI / Incentives','KPI incentives','Performance incentive','KPI/Comissions','KPI/Commissions','KPI / Commissions'];
    function guessMapping(sourceHeaders){
        const names=sourceHeaders.map(headerKey),mapping={},used=new Set();
        for(const field of headers){const matches=names.flatMap((name,index)=>name&&[field,...(aliases[field]||[])].map(headerKey).includes(name)?[index]:[]);mapping[field]=matches.length===1&&!used.has(matches[0])?matches[0]:null;if(mapping[field]!==null)used.add(mapping[field]);}
        return mapping;
    }
    function validateMapping(sourceHeaders,mapping){
        if(!sourceHeaders.length||sourceHeaders.length>100||Object.keys(mapping).some(field=>!headers.includes(field)))throw Error('Use supported payroll fields and at most 100 source columns.');
        const indices=Object.values(mapping).filter(value=>value!==null&&value!==undefined);
        if(indices.some(index=>!Number.isInteger(index)||index<0||index>=sourceHeaders.length)||new Set(indices).size!==indices.length)throw Error('Map each payroll field to a different available source column.');
        const required=requiredHeaders;
        const missing=required.filter(field=>mapping[field]===null||mapping[field]===undefined);if(mapping.employee==null&&mapping.employee_code==null)missing.push('employee or employee_code');
        if(missing.length)throw Error('Match the required columns: '+missing.join(', ')+'. Include explicit zero amounts in the source when nothing was deducted.');
    }
    function rowsFromMatrix(matrix,mapping){
        if(matrix.length<2||matrix.length>501)throw Error('Use one header row and between 1 and 500 employee rows.');
        if(mapping){validateMapping(matrix[0],mapping);return matrix.slice(1).map((cells,index)=>({row:index+2,values:Object.fromEntries(headers.map(field=>[field,String(mapping[field]==null?'':cells[mapping[field]]??'').trim()])),columnError:cells.length!==matrix[0].length,selected:false,skip:false,acknowledged:false,note:''}));}
        const names=matrix[0].map(headerKey),allowed=headers.map(headerKey);
        if(new Set(names).size!==names.length||names.some(name=>!allowed.includes(name))||requiredHeaders.some(name=>!names.includes(headerKey(name)))||(!names.includes(headerKey('employee'))&&!names.includes(headerKey('employee_code'))))throw Error('Use the payroll template columns. Optional columns: employee_code, taxable_benefits, review_basis.');
        return matrix.slice(1).map((cells,index)=>({row:index+2,values:Object.fromEntries(headers.map(name=>[name,String(cells[names.indexOf(headerKey(name))]??'').trim()])),columnError:cells.length!==names.length,selected:false,skip:false,acknowledged:false,note:''}));
    }
    function compare(employee,frequency,date){
        if(!['monthly','semi_monthly','weekly'].includes(frequency)||date<'2023-01-01')throw Error('Choose a payroll frequency and pay date from January 2023.');
        payroll.totals(employee);
        for(const field of payroll.amountFields){if(String(employee[field]??'').trim()==='')throw Error('Enter actual '+field+', including 0.00 when zero.');payroll.cents(employee[field]);}
        if(!['Taxable','Non-taxable','Minimum Wage Earner'].includes(employee.payType))throw Error('Choose Taxable, Non-taxable or Minimum Wage Earner.');
        const benefits=payroll.cents(employee.benefits);let taxableBenefits=0;
        if(benefits>0){if(String(employee.taxableBenefits??'').trim()==='')throw Error('Enter the taxable portion of benefits, including 0.00 when exempt.');taxableBenefits=payroll.cents(employee.taxableBenefits);if(taxableBenefits>benefits)throw Error('Taxable benefits cannot exceed total benefits.');}
        const basis=employee.reviewBasis||'regular';if(!['regular','year_end','final_pay','other'].includes(basis))throw Error('Review basis must be regular, year_end, final_pay or other.');
        const taxable=employee.payType==='Non-taxable'?0:Math.max(0,(employee.payType==='Minimum Wage Earner'?0:payroll.cents(employee.basic))+payroll.cents(employee.taxableAllowance)+payroll.taxableExtras(employee,employee.payType==='Minimum Wage Earner')+taxableBenefits-['sssEe','phicEe','hdmfEe','gsisEe','unionDues'].reduce((sum,key)=>sum+payroll.cents(employee[key]),0));
        const reference=automation.withholding(taxable,frequency),actual=payroll.cents(employee.withholding),difference=actual-reference;
        return {reference,actual,difference,needsReview:difference!==0||date.slice(5,7)==='12'||basis!=='regular'||employee.payType!=='Taxable'||['gsisEe','gsisEr','pera','otherNonTaxable'].some(field=>payroll.cents(employee[field])>0)};
    }
    function preview(rows,{employees,records=[],frequency='monthly',id='preview',contributionShare=null}){
        const errors=[],checks=[],included=rows.filter(row=>!row.skip),people=employees.filter(person=>!person.archived),seen=new Set();let first=null;
        if(!included.length||included.length>500)return {errors:[{row:0,message:'Include between 1 and 500 employee rows.'}],checks:[],run:null};
        const matched=[];
        for(const row of included){try{
            if(row.columnError)throw Error('Column count does not match the header. Correct the source file and load it again.');
            const value=row.values,meta=['pay_date','run_name','period_start','period_end'].map(key=>key==='run_name'?value[key]:importer.date(value[key]));
            if(!first)first=meta;if(meta.some((value,index)=>value!==first[index]))throw Error('All rows must belong to the same payroll run and period.');
            if(![meta[0],meta[2],meta[3]].every(payroll.validDate)||meta[2]>meta[3])throw Error('Enter valid pay dates and period start / end dates (YYYY-MM-DD).');
            if(meta[1].length>120)throw Error('Run name must contain at most 120 characters.');
            const matches=people.filter(person=>value.employee_code?normalize(person.code)===normalize(value.employee_code):normalize(person.name)===normalize(value.employee));
            if(matches.length!==1)throw Error('Match this employee to one active record in Masters. Use employee_code for ambiguous names.');
            const person=matches[0];if(value.employee_code&&value.employee&&normalize(value.employee)!==normalize(person.name)&&normalize(value.employee)!==normalize(person.code))throw Error('Employee name and code identify different records.');
            if(seen.has(String(person.id)))throw Error('This employee appears more than once in the batch.');seen.add(String(person.id));
            let employee={employeeId:person.id,name:person.name,payType:value.pay_type||'Taxable',...Object.fromEntries(payroll.allAmountFields.map(field=>[field,amountText(['basic','withholding',...automation.fields].includes(field)?value[field]:(value[field]||'0.00'))])),taxableBenefits:amountText(value.taxable_benefits),reviewBasis:value.review_basis||'regular',monthlyBasic:amountText(value.monthlyBasic),monthlyCompensation:amountText(value.monthlyCompensation),monthlyFundSalary:amountText(value.monthlyFundSalary),contributionShare:value.contribution_share||''};
            employee=automation.fillImportedContributions(employee,{frequency,date:meta[0],id,records,share:contributionShare,periodStart:meta[2],periodEnd:meta[3]});
            const amounts=payroll.totals(employee);if(amounts.gross<=0||amounts.net<0)throw Error('Earnings must be positive and cover actual deductions.');
            const comparison=compare(employee,frequency,meta[0]);
            employee.withholdingReview={version:'BIR-2023-periodic-v1',reference:comparison.reference,difference:comparison.difference};
            matched.push(employee);checks.push({row:row.row,employee,...comparison,net:amounts.net});
        }catch(error){errors.push({row:row.row,message:error.message});}}
        let run=first?{id,number:'',date:first[0],name:first[1],periodStart:first[2],periodEnd:first[3],status:'draft',draftTouched:true,automation:false,frequency,clientPayrollImport:true,importReviewVersion:1,employees:matched}:null;
        if(run&&records.some(other=>other.status!=='void'&&other.status!=='archived'&&other.date===run.date&&other.periodStart===run.periodStart&&other.periodEnd===run.periodEnd))errors.push({row:0,message:'A payroll run already exists for this pay date and period.'});
        if(records.some(other=>other.status==='draft'))errors.push({row:0,message:'Finish or delete the existing draft before importing payroll.'});
        return {errors,checks,run,reviewCount:0,totalActual:checks.reduce((sum,item)=>sum+item.actual,0),totalReference:checks.reduce((sum,item)=>sum+item.reference,0)};
    }
    function signature(run){return JSON.stringify([run.date,run.periodStart,run.periodEnd,run.frequency,run.employees.map(employee=>[employee.employeeId,employee.payType,...payroll.allAmountFields.map(field=>payroll.cents(employee[field])),employee.taxableBenefits,employee.reviewBasis]).sort((a,b)=>String(a[0]).localeCompare(String(b[0])))]);}
    function cumulative(records,year,employees=[]){
        const totals=new Map(),names=new Map();
        for(const employee of [...employees,...records.flatMap(run=>run.employees)]){
            const id=employee.employeeId??employee.id;if(id===undefined)continue;
            const name=normalize(employee.name),ids=names.get(name)||new Set();ids.add(String(id));names.set(name,ids);
        }
        for(const run of records.filter(run=>run.status==='posted'&&run.date.startsWith(String(year))))for(const employee of run.employees){
            const known=names.get(normalize(employee.name)),key=String(employee.employeeId??(known?.size===1?[...known][0]:'name:'+normalize(employee.name))),item=totals.get(key)||{name:employee.name,actual:0,reference:0,uncompared:0};
            item.actual+=payroll.cents(employee.withholding);
            if(run.clientPayrollImport&&employee.withholdingReview)item.reference+=employee.withholdingReview.reference;else item.uncompared++;
            totals.set(key,item);
        }
        return [...totals.values()];
    }
    const api={headers,simpleHeaders,requiredHeaders,template,guessMapping,validateMapping,rowsFromMatrix,compare,preview,signature,cumulative,money};if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.PayrollImport=api;
})(typeof window==='undefined'?null:window);
