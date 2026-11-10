const test=require('node:test'),assert=require('node:assert/strict'),engine=require('../../public/js/payroll-import.js'),payroll=require('../../public/js/payroll-register.js');
const people=[{id:1,code:'EMP001',name:'Sample Employee'},{id:2,code:'OLD',name:'Archived Employee',archived:true}];
const data=(changes={})=>({pay_date:'2026-10-30',run_name:'Client payroll',period_start:'2026-10-01',period_end:'2026-10-31',employee:'Sample Employee',pay_type:'Taxable',...Object.fromEntries(payroll.amountFields.map(field=>[field,'0.00'])),basic:'30000.00',sssEe:'1500.00',phicEe:'750.00',hdmfEe:'200.00',withholding:'1000.00',...changes});
const rows=(changes={})=>engine.rowsFromMatrix([engine.headers,engine.headers.map(key=>data(changes)[key]||'')]);
const context={employees:people,records:[],frequency:'monthly',id:'import-one'};
test('client column aliases and extra columns map to the original payroll fields',()=>{const original=rows()[0].values,source=[...engine.headers].reverse().map(field=>({employee:'Employee Name',basic:'Basic Salary',withholding:'Tax Deducted'}[field]||field));source.push('Internal client comment');const mapping=engine.guessMapping(source),matrix=[source,[...engine.headers].reverse().map(field=>original[field]).concat('Ignore this')];const mapped=engine.rowsFromMatrix(matrix,mapping),result=engine.preview(mapped,context);assert.deepEqual(result.errors,[]);assert.equal(result.run.employees[0].withholding,'1000.00');assert.equal(result.checks[0].reference,100755);assert.deepEqual(engine.rowsFromMatrix(matrix,JSON.parse(JSON.stringify(mapping))),mapped);});
test('mapped payroll rejects missing deduction columns, duplicate source use and invalid indices',()=>{const source=engine.headers,mapping=engine.guessMapping(source),matrix=[source,source.map(field=>data()[field]||'')];for(const change of [{withholding:null},{basic:mapping.withholding},{basic:100},{surprise:0},{basic:'2'}])assert.throws(()=>engine.rowsFromMatrix(matrix,{...mapping,...change}));const duplicate=engine.guessMapping(['Tax Deducted','Tax Withheld']);assert.equal(duplicate.withholding,null);});
test('employee codes can replace name columns without inventing zero deductions',()=>{const source=engine.headers.filter(field=>field!=='employee'),mapping=engine.guessMapping(source),matrix=[source,source.map(field=>data({employee_code:'EMP001'})[field]||'')];assert.deepEqual(engine.preview(engine.rowsFromMatrix(matrix,mapping),context).errors,[]);matrix[1][mapping.withholding]='';assert.ok(engine.preview(engine.rowsFromMatrix(matrix,mapping),context).errors.length);});
test('Excel dates and formatted peso amounts normalize without changing actual values',()=>{const result=engine.preview(rows({pay_date:'10/30/2026',period_start:'10/01/2026',period_end:'10/31/2026',basic:'PHP 30,000.00',withholding:'₱1,000.00'}),context);assert.deepEqual(result.errors,[]);assert.equal(result.run.date,'2026-10-30');assert.equal(result.run.employees[0].basic,'30000.00');assert.equal(result.run.employees[0].withholding,'1000.00');assert.equal(result.checks[0].reference,100755);assert.ok(engine.preview(rows({basic:'30,00.00'}),context).errors.length);});
test('preview preserves actual deductions and computes the reference from reported employee contributions',()=>{const result=engine.preview(rows(),context);assert.deepEqual(result.errors,[]);assert.equal(result.checks[0].reference,100755);assert.equal(result.checks[0].actual,100000);assert.equal(result.checks[0].difference,-755);assert.equal(result.run.employees[0].withholding,'1000.00');assert.equal(result.run.automation,false);assert.equal(result.reviewCount,0);assert.equal(payroll.runTotals(result.run).net,2655000);});
test('matching uses active Masters IDs and codes and rejects unknown, ambiguous or repeated employees',()=>{for(const change of [{employee:'Missing'},{employee:'Archived Employee'},{employee_code:'EMP001',employee:'Other employee'}])assert.match(engine.preview(rows(change),context).errors[0].message,/Masters|different/);const pair=rows();pair.push({...structuredClone(pair[0]),row:3});assert.match(engine.preview(pair,context).errors[0].message,/more than once/);const ambiguous={...context,employees:[...people,{id:3,name:'Sample Employee'}]};assert.match(engine.preview(rows(),ambiguous).errors[0].message,/Masters/);assert.deepEqual(engine.preview(rows({employee_code:'EMP001'}),ambiguous).errors,[]);});
test('actual deductions post without per-row acknowledgement or explanations',()=>{const list=rows();assert.equal(engine.preview(list,context).reviewCount,0);list[0].acknowledged=true;assert.equal(engine.preview(list,context).reviewCount,0);list[0].note='Client confirmed actual deduction; review year-end calculation.';assert.equal(engine.preview(list,context).reviewCount,0);const matched=engine.preview(rows({withholding:'1007.55'}),context);assert.equal(matched.reviewCount,0);});
test('December, special pay and exempt classifications do not add manual review gates',()=>{for(const change of [{pay_date:'2026-12-30',period_start:'2026-12-01',period_end:'2026-12-31',withholding:'1007.55'},{review_basis:'final_pay',withholding:'1007.55'},{pay_type:'Non-taxable',withholding:'0.00'}])assert.equal(engine.preview(rows(change),context).reviewCount,0);});
test('missing actual amounts, benefits classification, invalid dates and over-deductions block posting',()=>{for(const change of [{withholding:''},{benefits:'5000.00'},{benefits:'100.00',taxable_benefits:'101.00'},{pay_date:'2026-02-30'},{withholding:'999999.00'},{basic:'-1'},{review_basis:'invented'}])assert.ok(engine.preview(rows(change),context).errors.length);assert.deepEqual(engine.preview(rows({benefits:'5000.00',taxable_benefits:'0.00'}),context).errors,[]);});
test('frequency changes affect only the comparison and existing runs and drafts block repeated import',()=>{const list=rows(),original=JSON.stringify(list);for(const frequency of ['monthly','semi_monthly','weekly']){const result=engine.preview(list,{...context,frequency});assert.equal(result.run.employees[0].withholding,'1000.00');}assert.equal(JSON.stringify(list),original);const run=engine.preview(list,context).run;assert.match(engine.preview(list,{...context,records:[{...run,status:'posted'}]}).errors[0].message,/already exists/);assert.match(engine.preview(list,{...context,records:[{...run,date:'2026-09-01'}]}).errors[0].message,/existing draft/);});
test('skipped errors stay excluded and signatures keep actuals independent from review acknowledgements',()=>{const list=rows();list.push({row:3,values:{},skip:true});const first=engine.preview(list,context);list[0].note='Reviewed';list[0].acknowledged=true;assert.equal(engine.signature(first.run),engine.signature(engine.preview(list,context).run));list[0].values.withholding='1100.00';assert.notEqual(engine.signature(first.run),engine.signature(engine.preview(list,context).run));});
test('annual review keeps actual tax from older payroll without inventing missing reference amounts',()=>{const run=engine.preview(rows(),context).run;const totals=engine.cumulative([{...run,status:'posted'},{...run,clientPayrollImport:false,status:'posted'},{...run,status:'void'}],2026);assert.equal(totals[0].actual,200000);assert.equal(totals[0].reference,100755);assert.equal(totals[0].uncompared,1);});

test('simple payroll template with incentive columns computes blank monthly contributions and preserves actual tax',()=>{
 const headers=engine.simpleHeaders, values={pay_date:'2026-10-31',period_start:'2026-10-01',period_end:'2026-10-31',employee:'Sample Employee',basic:'30000.00',withholding:'1000.00'};
 const result=engine.preview(engine.rowsFromMatrix([headers,headers.map(key=>values[key]||'')]),context);
 assert.deepEqual(result.errors,[]);const employee=result.run.employees[0];
 assert.equal(employee.payType,'Taxable');assert.equal(employee.sssEe,'1500.00');assert.equal(employee.sssEr,'3030.00');assert.equal(employee.phicEe,'750.00');assert.equal(employee.hdmfEe,'200.00');assert.equal(employee.withholding,'1000.00');assert.equal(employee.contributionSources.sssEe,'calculated');assert.equal(result.checks[0].reference,100755);
 assert.equal(engine.template(true).split('\r\n')[0].split(',').length,9);
});
test('provided zero and client contributions are preserved while only missing amounts calculate',()=>{
 const result=engine.preview(rows({sssEe:'0.00',phicEe:'450.00',hdmfEe:'',sssEr:'',phicEr:'',hdmfEr:''}),context);
 assert.deepEqual(result.errors,[]);const employee=result.run.employees[0];assert.equal(employee.sssEe,'0.00');assert.equal(employee.phicEe,'450.00');assert.equal(employee.hdmfEe,'200.00');assert.equal(employee.withholding,'1000.00');assert.equal(employee.contributionSources.sssEe,'provided');
});
test('blank contributions respect monthly caps and non-monthly bases without replacing tax',()=>{
 const changes={sssEe:'',phicEe:'',hdmfEe:'',sssEr:'',phicEr:'',hdmfEr:''};
 const weekly=engine.preview(rows(changes),{...context,frequency:'weekly',contributionShare:'25'});assert.ok(weekly.errors.length);
 const actual=engine.preview(rows({...changes,monthlyBasic:'30000.00',monthlyCompensation:'30000.00',monthlyFundSalary:'30000.00'}),{...context,frequency:'weekly',contributionShare:'25'});assert.deepEqual(actual.errors,[]);assert.equal(actual.run.employees[0].sssEe,'375.00');assert.equal(actual.run.employees[0].withholding,'1000.00');
 const paid={...engine.preview(rows(),context).run,status:'posted',id:'old',date:'2026-10-15',periodStart:'2026-10-01',periodEnd:'2026-10-15'};
 const capped=engine.preview(rows(changes),{...context,records:[paid]});assert.deepEqual(capped.errors,[]);assert.equal(capped.run.employees[0].sssEe,'0.00');assert.equal(capped.run.employees[0].phicEe,'0.00');
});

test('automatic contribution inputs cannot guess government, detailed earnings or part-month salary bases',()=>{
 const missing={sssEe:'',phicEe:'',hdmfEe:'',sssEr:'',phicEr:'',hdmfEr:''};
 for(const changes of [{...missing,pera:'100.00'},{...missing,holidayPay:'100.00'},{...missing,period_start:'2026-10-15'}])assert.ok(engine.preview(rows(changes),context).errors.length);
 const later={...engine.preview(rows(),context).run,id:'later',status:'posted',date:'2026-10-31',periodStart:'2026-10-16'};
 assert.ok(engine.preview(rows(missing),{...context,records:[later]}).errors.length);
});


test('KPI/Comissions header maps separately from basic and commission and computes the correct contribution bases',()=>{
 const headers=['Pay Date','Period Start','Period End','Employee','Pay Type','Basic','KPI/Comissions','Commission','WTax (actual)'];
 const mapping=engine.guessMapping(headers);
 assert.equal(mapping.kpiIncentive,6);assert.equal(mapping.commission,7);assert.equal(mapping.withholding,8);
 const result=engine.preview(engine.rowsFromMatrix([headers,['2026-10-31','2026-10-01','2026-10-31','Sample Employee','Taxable','10000','2000','3000','0']],mapping),context);
 assert.deepEqual(result.errors,[]);
 const person=result.run.employees[0];
 assert.equal(person.basic,'10000.00');assert.equal(person.kpiIncentive,'2000.00');assert.equal(person.commission,'3000.00');
 assert.equal(person.sssEe,'750.00');assert.equal(person.phicEe,'250.00');assert.equal(person.hdmfEe,'200.00');
 assert.equal(person.contributionCalculation.monthlyBasic,'10000.00');assert.equal(person.contributionCalculation.monthlyCompensation,'15000.00');
 assert.equal(payroll.runTotals(result.run).gross,1500000);assert.equal(person.withholding,'0.00');
});

test('provided incentive deductions and explicit monthly bases retain precedence over automatic defaults',()=>{
 const missing={sssEe:'',phicEe:'',hdmfEe:'',sssEr:'',phicEr:'',hdmfEr:''};
 const result=engine.preview(rows({...missing,basic:'1000.00',kpiIncentive:'200.00',commission:'100.00',monthlyBasic:'12000.00',monthlyCompensation:'8000.00',monthlyFundSalary:'1200.00',phicEe:'0.00',withholding:'100.00'}),context);
 assert.deepEqual(result.errors,[]);const person=result.run.employees[0];
 assert.equal(person.sssEe,'400.00');assert.equal(person.phicEe,'0.00');assert.equal(person.hdmfEe,'12.00');assert.equal(person.withholding,'100.00');
 assert.equal(person.kpiIncentive,'200.00');
});


test('full template order and client Others descriptions survive import without becoming numeric earnings',()=>{
 const financial=engine.headers.filter(field=>payroll.columnFields.includes(field));assert.deepEqual(financial,payroll.columnFields);
 const result=engine.preview(rows({otherAllowanceDescription:'Travel adjustment',otherAllowance:'100.00',otherSupplementaryDescription:'Performance bonus',otherSupplementary:'50.00'}),context);
 assert.deepEqual(result.errors,[]);const person=result.run.employees[0];
 assert.equal(person.otherAllowanceDescription,'Travel adjustment');assert.equal(person.otherSupplementaryDescription,'Performance bonus');assert.equal(payroll.totals(person).gross,3015000);
 const map=engine.guessMapping(['Basic *','Overtime','Night Diff','Representation','Transportation','Housing Allow','Others (Reg) Desc','Others (Reg)','Others (Supp) Desc','Others (Supp)']);
 for(const field of ['basic','overtimePay','nightDifferential','representationAllowance','transportationAllowance','housingAllowance','otherAllowanceDescription','otherAllowance','otherSupplementaryDescription','otherSupplementary'])assert.notEqual(map[field],null);
});
