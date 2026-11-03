const dashboardRoutes=JSON.parse(document.getElementById('dashboard-routes').textContent);
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebarNav');
const backdrop = document.getElementById('sidebarBackdrop');
const mobileViewport = window.matchMedia('(max-width: 767px)');
function updateSidebar() {
    const isOpen = mobileViewport.matches ? document.body.classList.contains('sidebar-open') : !document.body.classList.contains('sidebar-closed');
    sidebarToggle.setAttribute('aria-expanded', String(isOpen));
    sidebar.inert = !isOpen;
    backdrop.hidden = !(mobileViewport.matches && isOpen);
}
sidebarToggle.addEventListener('click', () => {
    document.body.classList.toggle(mobileViewport.matches ? 'sidebar-open' : 'sidebar-closed');
    updateSidebar();
});
backdrop.addEventListener('click', () => {
    document.body.classList.remove('sidebar-open');
    updateSidebar();
});
mobileViewport.addEventListener('change', () => {
    document.body.classList.remove('sidebar-open', 'sidebar-closed');
    updateSidebar();
});
updateSidebar();
document.querySelectorAll('[data-section-toggle]').forEach((button, index) => {
    const section = button.nextElementSibling;
    section.id = `sidebar-section-${index}`;
    button.setAttribute('aria-controls', section.id);
    button.addEventListener('click', () => {
        section.hidden = !section.hidden;
        button.setAttribute('aria-expanded', String(!section.hidden));
    });
});
const themeButton = document.getElementById('themeToggleBtn');
function updateTheme(isDark) {
    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    document.getElementById('themeToggleIcon').textContent = isDark ? 'light_mode' : 'dark_mode';
    themeButton.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
    themeButton.title = isDark ? 'Switch to light theme' : 'Switch to dark theme';
}
try { updateTheme(localStorage.getItem('kontadurya.workspace.theme') !== 'light'); } catch { updateTheme(true); }
themeButton.addEventListener('click', () => {
    const isDark = !document.documentElement.classList.contains('dark');
    updateTheme(isDark);
    try { localStorage.setItem('kontadurya.workspace.theme', isDark ? 'dark' : 'light'); } catch { /* Storage may be unavailable. */ }
});
const workspaceDialog = document.getElementById('workspace-dialog');
const searchDialog = document.getElementById('workspace-search');
function openWorkspaceTopic(topic) {
    const page={Documents:'documents',Scans:'documents','My work':'work',Sales:'sales',Sheets:'sales',Collections:'collections',Purchases:'purchases',Payments:'payments',Journal:'journal',CWT:'cwt',WTP:'wtp',Books:'books',Masters:'masters','Financial Reports':'reports',Reports:'reports'}[topic];
    if (page) {
        window.location.assign(dashboardRoutes[page]);
        return;
    }
    document.body.classList.remove('sidebar-open');
    updateSidebar();
    if (searchDialog.open) searchDialog.close();
    document.getElementById('workspace-dialog-title').textContent = topic;
    let description = `${topic} is a planned workspace module. This module is not connected yet. Your posted records remain available in Sheets and Books.`;
    const profileDetails = document.querySelector('[data-dialog-profile]');
    if (profileDetails) profileDetails.hidden = topic !== 'Profile';
    if (topic === 'Profile') description = profileDetails ? 'Your signed-in account and access to the current company book.' : `Demo profile: ${workspaceDialog.dataset.owner}. This is a local session, not a verified account. Exit demo to clear the company details from this session.`;
    if (topic === 'Notifications') description = 'No workspace notifications. BIR services and external integrations are not connected.';
    if (topic === 'Create') description = 'Choose a transaction module to preview. Record entries in Sheets and review posted records in Books.';
    if (topic === 'Chart of accounts') description = 'The account structure preview will include assets, liabilities, equity, income, and expenses. No accounts have been seeded or reviewed yet.';
    document.getElementById('workspace-dialog-description').textContent = description;
    document.querySelector('[data-dialog-create]').hidden = topic !== 'Create';
    document.querySelector('[data-dialog-settings]').hidden = topic !== 'Profile' || Boolean(profileDetails);
    document.querySelector('[data-dialog-exit]').hidden = topic !== 'Profile' || Boolean(profileDetails);
    if (!workspaceDialog.open) workspaceDialog.showModal();
}
document.querySelectorAll('[data-workspace-topic]').forEach((element) => {
    element.addEventListener('click', (event) => {
        event.preventDefault();
        openWorkspaceTopic(element.dataset.workspaceTopic);
    });
});
const searchInput = document.getElementById('workspace-query');
const searchablePages = ['Home', 'Settings', 'My work', 'Sheets', 'Books', 'Masters', 'Sales', 'Purchases', 'Payments', 'Journal', 'Financial Reports', 'Tax Reports', 'Tax Returns', 'Tax Summary', 'Users & access', 'Clients'];
function renderSearchResults() {
    const results = document.querySelector('[data-search-results]');
    results.replaceChildren();
    const matches = searchablePages.filter((label) => label.toLowerCase().includes(searchInput.value.trim().toLowerCase()));
    matches.forEach((label) => {
        const pageKey={'My work':'work',Sales:'sales',Sheets:'sales',Collections:'collections',Purchases:'purchases',Payments:'payments',Journal:'journal',Books:'books',Masters:'masters','Financial Reports':'reports',Reports:'reports'}[label];
        const isPage = Boolean(pageKey)||['Home','Settings'].includes(label);
        const item = document.createElement(isPage ? 'a' : 'button');
        item.className = 'block w-full rounded-lg px-4 py-3 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800';
        item.textContent = label + (isPage ? '' : ' · Preview');
        if (isPage) item.href = pageKey ? dashboardRoutes[pageKey] : label === 'Home' ? searchDialog.dataset.dashboardUrl : searchDialog.dataset.setupUrl;
        else {
            item.type = 'button';
            item.addEventListener('click', () => openWorkspaceTopic(label));
        }
        results.append(item);
    });
    if (!matches.length) {
        const message = document.createElement('p');
        message.textContent = 'No matching workspace pages.';
        message.className = 'px-4 py-3 text-sm text-slate-500';
        results.append(message);
    }
}
function openSearch() {
    renderSearchResults();
    searchDialog.showModal();
    searchInput.focus();
}
document.querySelector('[data-search-open]').addEventListener('click', openSearch);
searchInput.addEventListener('input', renderSearchResults);
document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        if (workspaceDialog.open) workspaceDialog.close();
        if (!searchDialog.open) openSearch();
    }
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
        document.body.classList.remove('sidebar-open');
        updateSidebar();
        sidebarToggle.focus();
    }
});
[workspaceDialog, searchDialog].forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
});

const overview=document.querySelector('[data-dashboard-overview]');
function renderDashboard() {
    const dashboardReport=window.DashboardReports.build(window.KontaduryaLedger,overview.dataset.today,Number(overview.dataset.fiscalEnd));
    overview.hidden=false;
    for (const id of ['dashboard-calendar','dashboard-posting-chart','dashboard-income-chart','dashboard-periods']) document.getElementById(id).replaceChildren();
    const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(value/100);
    document.querySelectorAll('[data-dashboard-metric]').forEach(node=>{const value=dashboardReport[node.dataset.dashboardMetric];node.textContent=new Intl.NumberFormat('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100);node.classList.toggle('dash-negative',value<0);});
    const today=new Date(overview.dataset.today+'T12:00:00Z'),year=today.getUTCFullYear(),month=today.getUTCMonth();
    const monthLabel=today.toLocaleDateString('en-PH',{month:'long',year:'numeric',timeZone:'UTC'});
    document.querySelectorAll('[data-dashboard-month]').forEach(node=>node.textContent=monthLabel);
    const calendar=document.getElementById('dashboard-calendar');
    for(const label of ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']){const node=document.createElement('span');node.textContent=label;node.className='dash-day-name';node.setAttribute('role','columnheader');calendar.append(node);}
    for(let blank=0;blank<new Date(Date.UTC(year,month,1)).getUTCDay();blank++){const node=document.createElement('span');node.setAttribute('aria-hidden','true');calendar.append(node);}
    for(let day=1;day<=new Date(Date.UTC(year,month+1,0)).getUTCDate();day++){const node=document.createElement('span');node.textContent=day;node.setAttribute('role','gridcell');if(day===today.getUTCDate()){node.className='dash-today';node.setAttribute('aria-current','date');}calendar.append(node);}
    document.getElementById('dashboard-record-check').textContent=dashboardReport.balanced?'Posted debits and credits match. '+dashboardReport.posted+' voucher(s) through today.':'Review posted debit and credit totals in Books.';
    document.getElementById('dashboard-posting-count').textContent=dashboardReport.posted+' posted';
    const peak=dashboardReport.buckets.reduce((highest,bucket)=>bucket.posted>highest.posted?bucket:highest,dashboardReport.buckets[0]);
    document.getElementById('dashboard-posting-caption').textContent=peak.posted?'Peak: '+peak.period+' · '+peak.posted+' voucher(s)':'No posted vouchers through today.';
    function chart(target,keys){
        const chart=document.getElementById(target),maximum=Math.max(1,...dashboardReport.buckets.flatMap(bucket=>keys.map(key=>Math.abs(bucket[key]))));
        for(const bucket of dashboardReport.buckets){const column=document.createElement('div');column.className='dash-bar-column';const bars=document.createElement('div');bars.className='dash-bars';
            keys.forEach((key,index)=>{const bar=document.createElement('div');bar.className='dash-bar'+(index?' dash-bar-expense':'');bar.style.height=(Math.abs(bucket[key])/maximum*100)+'%';bar.title=bucket.period+' · '+key+': '+(key==='posted'?bucket[key]:money(bucket[key]));bars.append(bar);});
            const label=document.createElement('span');label.textContent=bucket.label;column.append(bars,label);chart.append(column);
        }
        chart.setAttribute('aria-label',dashboardReport.buckets.map(bucket=>bucket.period+': '+keys.map(key=>key+' '+(key==='posted'?bucket[key]:money(bucket[key]))).join(', ')).join('; '));
    }
    chart('dashboard-posting-chart',['posted']);chart('dashboard-income-chart',['income','expenses']);
    for(const [id,value] of [['dashboard-month-sales',dashboardReport.monthSales],['dashboard-month-purchases',dashboardReport.monthPurchases],['dashboard-net-vat',dashboardReport.netVat]])document.getElementById(id).textContent=money(value);
    for(const period of dashboardReport.periods){const row=document.createElement('tr');for(const value of [period.period,period.posted,period.drafts,period.posted?'Posted':'Nothing yet']){const cell=document.createElement('td');cell.textContent=value;row.append(cell);}document.getElementById('dashboard-periods').append(row);}
}

renderDashboard();
window.addEventListener('company-ledger:saved', renderDashboard);
