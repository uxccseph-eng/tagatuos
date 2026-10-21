const test=require('node:test'),assert=require('node:assert/strict'),ppe=require('../../public/js/ppe-schedule.js');
const asset={acquired_date:'2026-01-10',service_date:'2026-01-15',disposal_date:null,cost_cents:120000,residual_cents:12000,life_months:12};
test('straight-line starts at service month-end and preserves residual value',()=>{
    assert.equal(ppe.accumulated(asset,'2026-01-30'),0);
    assert.equal(ppe.accumulated(asset,'2026-01-31'),9000);
    assert.equal(ppe.accumulated(asset,'2026-12-31'),108000);
    assert.equal(ppe.accumulated(asset,'2030-12-31'),108000);
});
test('acquisition and monthly depreciation roll forward with exact costs',()=>{
    const result=ppe.calculate(asset,'2026-01-01','2026-01-31');
    assert.deepEqual(result,{opening:0,additions:120000,disposals:0,closing:120000,openingDep:0,periodDep:9000,removedDep:0,closingDep:9000,net:111000});
    const next=ppe.calculate(asset,'2026-02-01','2026-02-28');
    assert.equal(next.openingDep,result.closingDep);assert.equal(next.periodDep,9000);assert.equal(next.net,102000);
});
test('date ranges charge only month-ends actually inside the selected dates',()=>{
    assert.equal(ppe.calculate(asset,'2026-02-01','2026-02-15').periodDep,0);
    assert.equal(ppe.calculate(asset,'2026-02-15','2026-03-15').periodDep,9000);
    assert.equal(ppe.calculate(asset,'2026-02-01','2026-04-30').periodDep,27000);
});
test('disposal removes cost and accumulated depreciation without charging its month',()=>{
    const result=ppe.calculate({...asset,disposal_date:'2026-03-20'},'2026-03-01','2026-03-31');
    assert.equal(result.periodDep,0);assert.equal(result.disposals,120000);assert.equal(result.removedDep,18000);assert.equal(result.net,0);
    assert.equal(result.opening+result.additions-result.disposals,result.closing);
    assert.equal(result.openingDep+result.periodDep-result.removedDep,result.closingDep);
});
test('rounding reconciles total depreciation and service date may follow acquisition',()=>{
    const uneven={...asset,cost_cents:100,residual_cents:0,life_months:3};
    assert.equal(ppe.accumulated(uneven,'2026-01-31'),33);assert.equal(ppe.accumulated(uneven,'2026-02-28'),67);assert.equal(ppe.accumulated(uneven,'2026-03-31'),100);
    const delayed={...asset,service_date:'2026-04-01'};assert.equal(ppe.accumulated(delayed,'2026-03-31'),0);
    assert.equal(ppe.accumulated({...asset,residual_cents:asset.cost_cents},'2027-01-31'),0);
});
