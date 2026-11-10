(function(root){
    'use strict';
    function calculate(form,input,quarter,method,definitions){
        const values={};
        if(!['20','25'].includes(input.rate)||!['applicable','not_applicable'].includes(input.mcit))throw Error('Choose the reviewed rate and MCIT applicability.');
        if(input.rate==='20'&&!input.small_eligible)throw Error('Confirm small-corporation eligibility for the 20% rate.');
        for(const [key,definition] of Object.entries(definitions)){
            if(definition.type==='text'){values[key]=input[key]||'';continue;}
            if(!(definition.signed?/^-?\d{1,9}(?:\.00)?$/:/^\d{1,9}(?:\.00)?$/).test(input[key]||''))throw Error(definition.label+': enter whole pesos, including zero.');
            values[key]=Number(input[key]);
        }
        const sum=prefix=>Object.entries(values).filter(([key])=>key.startsWith(prefix)).reduce((n,[,v])=>n+v,0);
        const gross=values.sales-(values.returns||0)-values.cost+values.other_income;
        const ordinary=sum('expense_'),entered=form==='1702Q'?values.deductions:ordinary;
        if(method==='osd'&&entered!==0)throw Error('Remove itemized deductions when using OSD.');
        const deductions=method==='osd'?Math.round(Math.max(0,gross)*.4):entered;
        const taxable=gross-deductions+(values.previous_taxable||0);
        if(input.rate==='20'&&taxable>5000000)throw Error('Review the 20% rate: taxable income exceeds the supported threshold.');
        const rcit=Math.round(Math.max(0,taxable)*Number(input.rate)/100);
        const mcit=input.mcit==='applicable'?Math.round(Math.max(0,gross+(values.gross_q1||0)+(values.gross_q2||0))*.02):0;
        if(form==='1702-RT'&&values.book_net+values.addback1+values.addback2-values.recon_less1-values.recon_less2-values.recon_less3-values.recon_less4!==taxable)throw Error('Book-to-tax reconciliation must equal net taxable income: '+taxable.toLocaleString('en-PH')+'.');
        return {tax:Math.max(rcit,mcit)*100,credits:sum('credit_')*100,taxable,gross,rcit,mcit};
    }
    const api={calculate};if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.CorporateIncomeWorksheet=api;
})(typeof window==='undefined'?null:window);
