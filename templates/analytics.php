<div class="page-header"><h1>Analytics</h1><a class="btn secondary" href="<?= e(url('/')) ?>">&larr; Dashboard</a></div>

<div class="kpis">
  <div class="kpi"><div class="v"><?= e($kpis['requirements']) ?></div><div class="l">Requirements received</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['boms']) ?></div><div class="l">BOMs created</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['quotes']) ?></div><div class="l">Quotations</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['pending']) ?></div><div class="l">Pending quotes</div></div>
  <div class="kpi"><div class="v">&#8377;<?= inr($kpis['quote_value']) ?></div><div class="l">Total quoted value</div></div>
  <div class="kpi"><div class="v">&#8377;<?= inr($kpis['avg_quote']) ?></div><div class="l">Average quote value</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['conversion']) ?>%</div><div class="l">Requirements &rarr; quote sent</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['customers']) ?></div><div class="l">Customers</div></div>
</div>

<div class="charts">
  <div class="chart-card"><h3>Pipeline: requirement &rarr; quotation sent</h3><div id="c-funnel"></div></div>
  <div class="chart-card"><h3>BOM status (latest versions)</h3><div id="c-status"></div></div>
  <div class="chart-card"><h3>Activity per month</h3><div id="c-activity"></div></div>
  <div class="chart-card"><h3>Quoted value per month (&#8377;)</h3><div id="c-value"></div></div>
  <div class="chart-card"><h3>Quoted value by customer (&#8377;)</h3><div id="c-cust"></div></div>
  <div class="chart-card"><h3>BOM cost by component category (&#8377;)</h3><div id="c-cat"></div></div>
  <div class="chart-card"><h3>Cost / margin / GST in recent quotes (&#8377;)</h3><div id="c-split"></div></div>
  <div class="chart-card"><h3>Pending quotes by owner</h3><div id="c-owner"></div></div>
  <div class="chart-card"><h3>Requirements by sales person</h3><div id="c-sales"></div></div>
</div>
<div class="chart-tip" id="tip"></div>

<script>
const D = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const COL = ['#4c5bd4', '#e0822b', '#2f9e6e', '#c2417f', '#2a9bb5', '#8a6fd1', '#b5a02a', '#6b7a90'];
const NS = 'http://www.w3.org/2000/svg';
const tip = document.getElementById('tip');

function inr(n){ n=Math.round(n); var s=String(Math.abs(n)),t=s.slice(-3),h=s.slice(0,-3); if(h) t=h.replace(/\B(?=(\d{2})+(?!\d))/g,',')+','+t; return (n<0?'-':'')+t; }
function short(n){ if(n>=1e7) return (n/1e7).toFixed(1).replace(/\.0$/,'')+'Cr'; if(n>=1e5) return (n/1e5).toFixed(1).replace(/\.0$/,'')+'L'; if(n>=1e3) return (n/1e3).toFixed(0)+'k'; return String(n); }
function el(name, attrs, parent, text){ var e=document.createElementNS(NS,name); for(var k in attrs) e.setAttribute(k,attrs[k]); if(text!==undefined) e.textContent=text; if(parent) parent.appendChild(e); return e; }
function hover(node, msg){
  node.addEventListener('mousemove', e=>{ tip.style.display='block'; tip.textContent=msg; tip.style.left=(e.clientX+12)+'px'; tip.style.top=(e.clientY+12)+'px'; });
  node.addEventListener('mouseleave', ()=>tip.style.display='none');
}
function mk(id, w, h){ var host=document.getElementById(id); host.innerHTML=''; return el('svg',{viewBox:'0 0 '+w+' '+h}, host); }
function empty(id){ document.getElementById(id).innerHTML='<div class="empty-state" style="padding:30px;">No data yet</div>'; }
function legend(id, names){ var d=document.createElement('div'); d.className='legend'; names.forEach((n,i)=>{ d.innerHTML+='<span><i style="background:'+COL[i%COL.length]+'"></i>'+n+'</span>'; }); document.getElementById(id).appendChild(d); }
function niceMax(v){ if(v<=0) return 1; if(v<=12) return Math.ceil(v/4)*4; var p=Math.pow(10,Math.floor(Math.log10(v))); var f=v/p; return (f<=1?1:f<=2?2:f<=5?5:10)*p; }

// vertical grouped / stacked bars. series=[{name, values}], labels=[]
function bars(id, labels, series, opt){
  opt=opt||{}; var all=series.reduce((a,s)=>a.concat(s.values),[]);
  if(!labels.length || !all.some(v=>v>0)) return empty(id);
  var W=520,H=250,L=52,R=10,T=10,B=34, pw=W-L-R, ph=H-T-B;
  var svg=mk(id,W,H);
  var max=opt.stacked ? Math.max.apply(null, labels.map((_,i)=>series.reduce((a,s)=>a+s.values[i],0))) : Math.max.apply(null, all);
  max=niceMax(max);
  for(var g=0; g<=4; g++){ var y=T+ph-ph*g/4; el('line',{x1:L,x2:W-R,y1:y,y2:y,stroke:'#e4e6f0'},svg); el('text',{x:L-6,y:y+4,'text-anchor':'end','font-size':10,fill:'#6b6f8a'},svg,opt.money?short(max*g/4):Math.round(max*g/4)); }
  var gw=pw/labels.length, bw=opt.stacked? Math.min(36,gw*0.6) : Math.min(28, gw*0.7/series.length);
  labels.forEach((lb,i)=>{
    var cx=L+gw*i+gw/2, acc=0;
    el('text',{x:cx,y:H-14,'text-anchor':'middle','font-size':10,fill:'#6b6f8a'},svg,String(lb).length>9?String(lb).slice(0,8)+'…':lb);
    series.forEach((s,k)=>{
      var v=s.values[i], h=ph*v/max, x, y;
      if(opt.stacked){ x=cx-bw/2; y=T+ph-acc-h; acc+=h; } else { x=cx-bw*series.length/2+bw*k; y=T+ph-h; }
      var r=el('rect',{x:x,y:y,width:Math.max(bw-1,1),height:Math.max(h,0),rx:2,fill:COL[k%COL.length]},svg);
      hover(r, lb+(series.length>1?' · '+s.name:'')+': '+(opt.money?'₹'+inr(v):v));
    });
  });
  if(series.length>1) legend(id, series.map(s=>s.name));
}

// horizontal bars from [[label,value]]
function hbars(id, rows, money){
  if(!rows.length || !rows.some(r=>r[1]>0)) return empty(id);
  var rh=30, W=520, H=rows.length*rh+10, L=150, svg=mk(id,W,H), max=Math.max.apply(null,rows.map(r=>r[1]));
  rows.forEach((r,i)=>{
    var y=i*rh+4, w=(W-L-70)*r[1]/max;
    el('text',{x:L-8,y:y+16,'text-anchor':'end','font-size':11,fill:'#23253a'},svg,String(r[0]).length>22?String(r[0]).slice(0,21)+'…':r[0]);
    var b=el('rect',{x:L,y:y+3,width:Math.max(w,2),height:18,rx:3,fill:COL[0]},svg);
    el('text',{x:L+w+6,y:y+16,'font-size':11,fill:'#6b6f8a'},svg,money?'₹'+short(r[1]):r[1]);
    hover(b, r[0]+': '+(money?'₹'+inr(r[1]):r[1]));
  });
}

// funnel (horizontal, decreasing)
function funnel(id, rows){
  if(!rows.some(r=>r[1]>0)) return empty(id);
  var W=520, rh=38, H=rows.length*rh+4, svg=mk(id,W,H), max=Math.max.apply(null,[1].concat(rows.map(r=>r[1])));
  rows.forEach((r,i)=>{
    var w=Math.max(6,(W-190)*r[1]/max), y=i*rh+4;
    el('text',{x:100,y:y+20,'text-anchor':'end','font-size':12,fill:'#23253a'},svg,r[0]);
    var b=el('rect',{x:108,y:y+4,width:w,height:24,rx:3,fill:COL[0],opacity:1-i*0.14},svg);
    el('text',{x:108+w+8,y:y+21,'font-size':12,'font-weight':700,fill:'#23253a'},svg,r[1]);
    hover(b, r[0]+': '+r[1]);
  });
}

// donut from [[label,value]]
function donut(id, rows){
  var total=rows.reduce((a,r)=>a+r[1],0); if(!total) return empty(id);
  var W=520,H=210,cx=120,cy=105,R=84,r=52, svg=mk(id,W,H), ang=-Math.PI/2;
  rows.forEach((row,i)=>{
    var a=2*Math.PI*row[1]/total, a2=ang+a, large=a>Math.PI?1:0;
    if(rows.length===1){ a2=ang+2*Math.PI-0.0001; }
    var p='M'+(cx+R*Math.cos(ang))+' '+(cy+R*Math.sin(ang))+' A'+R+' '+R+' 0 '+large+' 1 '+(cx+R*Math.cos(a2))+' '+(cy+R*Math.sin(a2))+
          ' L'+(cx+r*Math.cos(a2))+' '+(cy+r*Math.sin(a2))+' A'+r+' '+r+' 0 '+large+' 0 '+(cx+r*Math.cos(ang))+' '+(cy+r*Math.sin(ang))+' Z';
    var s=el('path',{d:p,fill:COL[i%COL.length],stroke:'#fff','stroke-width':2},svg);
    hover(s,row[0]+': '+row[1]+' ('+Math.round(100*row[1]/total)+'%)');
    ang=a2;
    el('rect',{x:250,y:30+i*24,width:11,height:11,rx:2,fill:COL[i%COL.length]},svg);
    el('text',{x:268,y:40+i*24,'font-size':12,fill:'#23253a'},svg,row[0]+'  ·  '+row[1]);
  });
  el('text',{x:cx,y:cy+7,'text-anchor':'middle','font-size':22,'font-weight':700,fill:'#2d3250'},svg,total);
}

// lines (multi series)
function lines(id, labels, series){
  var all=series.reduce((a,s)=>a.concat(s.values),[]); if(!all.some(v=>v>0)) return empty(id);
  var W=520,H=240,L=40,R=14,T=12,B=30,pw=W-L-R,ph=H-T-B, max=niceMax(Math.max.apply(null,all)), svg=mk(id,W,H);
  for(var g=0; g<=4; g++){ var y=T+ph-ph*g/4; el('line',{x1:L,x2:W-R,y1:y,y2:y,stroke:'#e4e6f0'},svg); el('text',{x:L-6,y:y+4,'text-anchor':'end','font-size':10,fill:'#6b6f8a'},svg,Math.round(max*g/4)); }
  var step=pw/(labels.length-1||1);
  labels.forEach((lb,i)=>el('text',{x:L+step*i,y:H-10,'text-anchor':'middle','font-size':10,fill:'#6b6f8a'},svg,lb.slice(2)));
  series.forEach((s,k)=>{
    var pts=s.values.map((v,i)=>[L+step*i, T+ph-ph*v/max]);
    el('polyline',{points:pts.map(p=>p.join(',')).join(' '),fill:'none',stroke:COL[k],'stroke-width':2.2},svg);
    pts.forEach((p,i)=>{ var c=el('circle',{cx:p[0],cy:p[1],r:4,fill:COL[k],stroke:'#fff','stroke-width':1.5},svg); hover(c,s.name+' · '+labels[i]+': '+s.values[i]); });
  });
  legend(id, series.map(s=>s.name));
}

funnel('c-funnel', D.funnel);
donut('c-status', D.bom_status);
lines('c-activity', D.months, [{name:'Requirements',values:D.req_m},{name:'BOMs',values:D.bom_m},{name:'Quotations',values:D.quote_m}]);
bars('c-value', D.months.map(m=>m.slice(2)), [{name:'Quoted value',values:D.quote_value_m}], {money:true});
hbars('c-cust', D.by_customer, true);
hbars('c-cat', D.by_category, true);
bars('c-split', D.quote_split.map(r=>r[0]), [
  {name:'Component cost',values:D.quote_split.map(r=>r[1])},
  {name:'Margin',values:D.quote_split.map(r=>r[2])},
  {name:'GST',values:D.quote_split.map(r=>r[3])}], {stacked:true, money:true});
donut('c-owner', D.pending_owner);
hbars('c-sales', D.by_sales, false);
</script>
