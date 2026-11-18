/* Астана: парадные сцены — сцена, места, камера, интерфейс. Данные: data/city.json, data/places.json,
   data/places.private.json (личные места, не в git). Модели зданий: models/*.js через js/kit.js. */
(async function(){
const $=s=>document.querySelector(s);
const load=async(u)=>{const r=await fetch(u);if(!r.ok)throw new Error(u+': '+r.status);return r.json();};
let D, VENUES;
try{ [D,VENUES]=await Promise.all([load('data/city.json'),load('data/places.json').then(p=>p.places)]); }
catch(e){ $('#load').textContent='Не удалось загрузить данные карты ('+e.message+').'; return; }
// личные места (дома, работа, участок) лежат в отдельном файле, которого нет в публичном репозитории
const MINE=await load('data/places.private.json').then(p=>p.places).catch(()=>[]);
for(const p of MINE){ p.h=p.hb+4; if(p.kind==='plot') p.az=Math.atan2(-p.w[0],p.w[1])+.55; VENUES.push(p); }
const hidden=new Set(MINE.flatMap(p=>p.hide||[]));
const hex=c=>parseInt(c.slice(1),16);

/* ---------- scene ---------- */
const canvas=$('#c'), stage=$('#stage');
const renderer=new THREE.WebGLRenderer({canvas,antialias:true,alpha:true,powerPreference:'high-performance'});
renderer.setPixelRatio(Math.min(devicePixelRatio||1,2));
const scene=new THREE.Scene();
const camera=new THREE.PerspectiveCamera(38,1,4,160000);
const controls=new THREE.OrbitControls(camera,canvas);
Object.assign(controls,{enableDamping:true,dampingFactor:.08,maxPolarAngle:1.5,minDistance:35,maxDistance:48000,autoRotateSpeed:.9,screenSpacePanning:false});
scene.add(new THREE.HemisphereLight(0xcfe6ff,0x0a1830,.9));
const sun=new THREE.DirectionalLight(0xffffff,.9); sun.position.set(-.5,1,.6); scene.add(sun);

const kit=AstanaKit.makeKit(THREE), {th,bm,ADD,NRM,solid,white,add,edges}=kit;
const dec=(a,o,s)=>{const r=[];let x=0,y=0;for(let i=o;i<a.length;i+=2){x+=a[i];y+=a[i+1];r.push([x/s,y/s]);}return r;};
const flat=(ring,y,mat)=>{
  const g=new THREE.ShapeGeometry(new THREE.Shape(ring.map(p=>new THREE.Vector2(p[0],p[1])))); g.rotateX(-Math.PI/2);
  const m=new THREE.Mesh(g,mat); m.position.y=y; scene.add(m); return m;
};
// ground, parks, water
const ground=new THREE.Mesh(new THREE.CircleGeometry(90000,64),th(new THREE.MeshBasicMaterial(),{color:0x040c1c},{color:0xdfeaf6}));
ground.rotation.x=-Math.PI/2; ground.position.y=-1; scene.add(ground);
const parkMat=bm({color:0x0a3a4a,opacity:.35,blending:ADD},{color:0xbfe3cc,opacity:.95,blending:NRM});
D.parks.forEach(a=>flat(dec(a,0,1),.1,parkMat));
const waterMat=bm({color:0x1668b8,opacity:.55,blending:ADD},{color:0x8cc6f4,opacity:.95,blending:NRM});
D.water.forEach(a=>flat(dec(a,0,1),.3,waterMat));

// roads as flat ribbons
{
  const pos=[],W=[5,9,15,22];
  for(const r of D.roads){const w=W[r[0]]/2,p=dec(r,1,1);
    for(let i=0;i<p.length-1;i++){const a=p[i],b=p[i+1];let nx=-(b[1]-a[1]),ny=b[0]-a[0];const l=Math.hypot(nx,ny)||1;nx=nx/l*w;ny=ny/l*w;
      pos.push(a[0]+nx,.5,-(a[1]+ny), a[0]-nx,.5,-(a[1]-ny), b[0]-nx,.5,-(b[1]-ny),  a[0]+nx,.5,-(a[1]+ny), b[0]-nx,.5,-(b[1]-ny), b[0]+nx,.5,-(b[1]+ny));}}
  const g=new THREE.BufferGeometry(); g.setAttribute('position',new THREE.Float32BufferAttribute(pos,3));
  scene.add(new THREE.Mesh(g,bm({color:0x2a7fd0,opacity:.28,blending:ADD},{color:0xffffff,opacity:.95,blending:NRM})));
}

// the city: every building as a translucent blue prism + roof outline.
// Vertex "color" holds one shade value (0 at the ground, up to 1 on tall roofs); the shader maps it between two theme colours.
const cityU={uLo:{value:new THREE.Color()},uHi:{value:new THREE.Color()}};
{
  let nv=0,nl=0; const rings=[];
  D.b=D.b.filter((b,i)=>!hidden.has(i));
  for(const b of D.b){const r=dec(b,1,2); rings.push(r); nv+=r.length*6+(r.length-2)*3; nl+=r.length*2+(b[0]>=14?r.length*2:0);}
  const pos=new Float32Array(nv*3), col=new Uint8Array(nv*3), lp=new Float32Array(nl*3);
  let k=0,c=0,l=0;
  const v=(x,y,h,t)=>{pos[k++]=x;pos[k++]=h;pos[k++]=-y; col[c++]=t;col[c++]=t;col[c++]=t;};
  D.b.forEach((b,bi)=>{
    const h=b[0], r=rings[bi], n=r.length, top=Math.round(255*Math.min(1,.55+h/90)), roof=Math.round(top*.8);
    for(let i=0;i<n;i++){const a=r[i],q=r[(i+1)%n];
      v(a[0],a[1],0,0);v(q[0],q[1],0,0);v(q[0],q[1],h,top); v(a[0],a[1],0,0);v(q[0],q[1],h,top);v(a[0],a[1],h,top);
      lp[l++]=a[0];lp[l++]=h;lp[l++]=-a[1]; lp[l++]=q[0];lp[l++]=h;lp[l++]=-q[1];
      if(h>=14){lp[l++]=a[0];lp[l++]=0;lp[l++]=-a[1]; lp[l++]=a[0];lp[l++]=h;lp[l++]=-a[1];}
    }
    const tri=THREE.ShapeUtils.triangulateShape(r.map(p=>new THREE.Vector2(p[0],p[1])),[]);
    for(const t of tri) for(const i of t) v(r[i][0],r[i][1],h,roof);
  });
  const g=new THREE.BufferGeometry();
  g.setAttribute('position',new THREE.BufferAttribute(pos.subarray(0,k),3)); g.setAttribute('color',new THREE.BufferAttribute(col.subarray(0,c),3,true));
  const cm=new THREE.MeshBasicMaterial({vertexColors:true,transparent:true,side:THREE.DoubleSide});
  cm.onBeforeCompile=sh=>{sh.uniforms.uLo=cityU.uLo;sh.uniforms.uHi=cityU.uHi;
    sh.fragmentShader='uniform vec3 uLo;uniform vec3 uHi;\n'+sh.fragmentShader.replace('#include <color_fragment>','diffuseColor.rgb=mix(uLo,uHi,vColor.r);');};
  th(cm,{blending:ADD,depthWrite:false,opacity:1},{blending:NRM,depthWrite:true,opacity:.72});
  scene.add(new THREE.Mesh(g,cm));
  const lg=new THREE.BufferGeometry(); lg.setAttribute('position',new THREE.BufferAttribute(lp.subarray(0,l),3));
  scene.add(new THREE.LineSegments(lg,th(new THREE.LineBasicMaterial({transparent:true,depthWrite:false}),{color:0x5cc2ff,opacity:.2,blending:ADD},{color:0x1d6fd0,opacity:.3,blending:NRM})));
}

const extrude=(ring,h,mat,y=0)=>{const g=new THREE.Group();scene.add(g);return kit.extrude(g,ring,h,mat,y);};

/* ---------- buildings with their own models (models/*.js) ---------- */
for(const v of VENUES){
  if(!v.model) continue;
  const d=D.venues[v.id], make=MODELS[v.model];
  if(!d||!make){ console.warn('нет модели или контура для',v.id); continue; }
  scene.add(AstanaKit.place(make(THREE,AstanaKit.ctxFor(kit,d,{color:hex(v.color)})),d));
}
if(MODELS.landmarks) scene.add(MODELS.landmarks(THREE,Object.assign({},kit,{lm:D.lm})));

/* ---------- personal places (data/places.private.json) ---------- */
for(const p of MINE) if(p.kind==='footprint'){ // a building: its real footprint, lifted to its height
  edges(extrude(p.ring,p.hb,solid(hex(p.color),.3)),white,30);
}

for(const b of MINE) if(b.kind==='plot'){ // a plot with the house from the «Наш дом» project (sizes approximate)
  const H=b.house, g=new THREE.Group();
  g.matrixAutoUpdate=false;
  g.matrix.makeBasis(new THREE.Vector3(b.u[0],0,-b.u[1]),new THREE.Vector3(0,1,0),new THREE.Vector3(b.w[0],0,-b.w[1])).setPosition(b.o[0],0,-b.o[1]);
  scene.add(g);
  const pad=flat(b.pts,.6,th(new THREE.MeshBasicMaterial({color:hex(b.color),transparent:true,depthWrite:false,side:THREE.DoubleSide}),{opacity:.16,blending:ADD},{opacity:.35,blending:NRM}));
  const fence=new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(b.pts.map(p=>new THREE.Vector3(p[0],1,-p[1]))),new THREE.LineBasicMaterial({color:hex(b.color)})); scene.add(fence);
  const ax=H.at[0], ad=H.at[1], hw=H.wall, wallM=solid(0xf3eee2,.1), roofM=solid(0x4a4f58,.05);
  const wg=new THREE.ExtrudeGeometry(new THREE.Shape(H.fp.map(p=>new THREE.Vector2(ax+p[0],-(ad+p[1])))),{depth:hw,bevelEnabled:false}); wg.rotateX(-Math.PI/2);
  edges(add(g,wg,wallM),white,30);
  const over=.5, x0=ax-over, x1=ax+H.w+over, d0=ad+H.y0, dr=ad+H.ridgeY, d1=ad+H.d, top=hw+H.ridge;
  const e0=hw-over*H.ridge/(dr-d0), e1=hw-over*H.ridge/(d1-dr);
  const rg=new THREE.BufferGeometry(); rg.setAttribute('position',new THREE.Float32BufferAttribute([
    x0,e0,d0-over, x1,e0,d0-over, x1,top,dr,  x0,e0,d0-over, x1,top,dr, x0,top,dr,
    x0,top,dr, x1,top,dr, x1,e1,d1+over,  x0,top,dr, x1,e1,d1+over, x0,e1,d1+over],3)); rg.computeVertexNormals();
  add(g,rg,roofM);
  const gg=new THREE.BufferGeometry(); gg.setAttribute('position',new THREE.Float32BufferAttribute([
    ax,hw,d0, ax,top,dr, ax,hw,d1,  ax+H.w,hw,d0, ax+H.w,top,dr, ax+H.w,hw,d1],3)); gg.computeVertexNormals();
  add(g,gg,wallM);
  const o=b.out; add(g,new THREE.BoxGeometry(o.w,o.h,o.len),solid(0xd9d2c0,.08),b.plotW-o.w/2,o.h/2,o.from+o.len/2);
}

/* ---------- halos, beams, labels ---------- */
const haloTex=(()=>{const c=document.createElement('canvas');c.width=c.height=256;const x=c.getContext('2d'),g=x.createRadialGradient(128,128,10,128,128,128);
  g.addColorStop(0,'rgba(255,255,255,.9)');g.addColorStop(.35,'rgba(255,255,255,.28)');g.addColorStop(1,'rgba(255,255,255,0)');x.fillStyle=g;x.fillRect(0,0,256,256);return new THREE.CanvasTexture(c);})();
const labels=$('#labels'), places=$('#places'), rings=[];
VENUES.forEach((v,i)=>{
  const d=v.kind?v:D.venues[v.id]; v.color=hex(v.color); v.pos=new THREE.Vector3(d.c[0],0,-d.c[1]); v.size=d.L;
  const halo=new THREE.Mesh(new THREE.PlaneGeometry(d.L*3.2,d.L*3.2),th(new THREE.MeshBasicMaterial({map:haloTex,color:v.color,transparent:true,depthWrite:false}),{opacity:.5,blending:ADD},{opacity:.75,blending:NRM}));
  halo.rotation.x=-Math.PI/2; halo.position.copy(v.pos).setY(.8); scene.add(halo);
  const ring=new THREE.Mesh(new THREE.RingGeometry(.96,1,96),th(new THREE.MeshBasicMaterial({color:v.color,transparent:true,opacity:.8,depthWrite:false,side:THREE.DoubleSide}),{blending:ADD},{blending:NRM}));
  ring.rotation.x=-Math.PI/2; ring.position.copy(v.pos).setY(1); ring.userData={r:d.L*.75,ph:i*.7}; scene.add(ring); rings.push(ring);
  const beam=new THREE.Mesh(new THREE.CylinderGeometry(3,3,900,8,1,true),th(new THREE.MeshBasicMaterial({color:v.color,transparent:true,opacity:.22,depthWrite:false}),{blending:ADD},{blending:NRM}));
  beam.position.copy(v.pos).setY(450+v.h); scene.add(beam); v.beam=beam;
  
  v.el=document.createElement('div'); v.el.className='lbl'; v.el.style.setProperty('--lc','#'+v.color.toString(16).padStart(6,'0')); v.el.innerHTML='<b></b><i></i>'; v.el.querySelector('b').textContent=v.name; labels.appendChild(v.el);
  const b=document.createElement('button'); b.className='place'; b.id='place-'+v.id; b.style.setProperty('--cd','#'+v.color.toString(16).padStart(6,'0')); b.style.setProperty('--cl',v.colorLight||'');
  b.innerHTML='<kbd></kbd><div><em></em><span></span></div>'; b.querySelector('kbd').textContent=i<9?i+1:''; b.querySelector('em').textContent=v.name; b.querySelector('span').textContent=v.sub||''; b.onclick=()=>{stopTour();goVenue(i);}; places.appendChild(b); v.btn=b;
});

/* ---------- camera flights ---------- */
const OVER={t:new THREE.Vector3(1200,0,600),dist:19000,az:-.12,pol:.85};
const venueView=v=>({t:new THREE.Vector3(v.pos.x,v.h*.42,v.pos.z),dist:v.dist,az:v.az,pol:1.2});
const ease=t=>t<.5?4*t*t*t:1-Math.pow(-2*t+2,3)/2;
const place=(t,dist,az,pol)=>{controls.target.copy(t);camera.position.set(t.x+dist*Math.sin(pol)*Math.sin(az),t.y+dist*Math.cos(pol),t.z+dist*Math.sin(pol)*Math.cos(az));};
const current=()=>{const o=camera.position.clone().sub(controls.target),dist=o.length();return{t:controls.target.clone(),dist,az:Math.atan2(o.x,o.z),pol:Math.acos(THREE.MathUtils.clamp(o.y/dist,-1,1))};};
let flight=null, active=-1;
const speed=()=>+$('#speed').value;
function fly(to,dur){
  return new Promise(res=>{
    if(flight) flight.res(false);
    const from=current(); let da=to.az-from.az; da=Math.atan2(Math.sin(da),Math.cos(da));
    const ground=Math.hypot(to.t.x-from.t.x,to.t.z-from.t.z);
    const lift=Math.min(ground*.95,5200)*(Math.max(from.dist,to.dist)>4000?.15:1);
    controls.autoRotate=false; controls.enabled=false;
    flight={from,to,da,lift,t0:performance.now(),dur:dur*1000*(matchMedia('(prefers-reduced-motion: reduce)').matches?0.01:1),res};
  });
}
function stepFlight(now){
  if(!flight) return;
  const f=flight, raw=Math.min(1,(now-f.t0)/f.dur), k=ease(raw), bump=Math.sin(Math.PI*k);
  const t=f.from.t.clone().lerp(f.to.t,k);
  const dist=Math.exp(THREE.MathUtils.lerp(Math.log(f.from.dist),Math.log(f.to.dist),k))+bump*f.lift;
  const pol=THREE.MathUtils.lerp(f.from.pol,f.to.pol,k)-bump*(f.lift>800?.32:0);
  place(t,dist,f.from.az+f.da*k,Math.max(.25,pol));
  if(raw>=1){flight=null;controls.enabled=true;f.res(true);}
}
const mark=i=>{active=i;VENUES.forEach((v,j)=>v.btn.classList.toggle('on',i===j));};
async function goVenue(i){mark(i);const ok=await fly(venueView(VENUES[i]),speed());if(ok)controls.autoRotate=true;return ok;}
async function goOver(){mark(-1);return fly(OVER,speed());}
let tour=0;
const wait=ms=>new Promise(r=>setTimeout(r,ms));
function stopTour(){tour++;$('#b-tour').firstChild.textContent='Тур по залам';}
async function startTour(){
  const my=++tour; $('#b-tour').firstChild.textContent='Остановить тур';
  if(!(await goOver())||my!==tour) return; await wait(1200);
  for(let i=0;i<VENUES.length&&my===tour;i++){ if(VENUES[i].tour===false) continue; if(!(await goVenue(i))) return; await wait(4500); }
  if(my===tour){await goOver(); if(my===tour) stopTour();}
}
controls.addEventListener('start',()=>{controls.autoRotate=false;if(flight){flight.res(false);flight=null;controls.enabled=true;}stopTour();});

/* ---------- UI ---------- */
const toggle=(btn,cls,inv)=>{const on=document.body.classList.toggle(cls);$(btn).setAttribute('aria-pressed',String(inv?!on:on));resize();};
$('#b-tour').onclick=()=>$('#b-tour').textContent.startsWith('Ост')?stopTour():startTour();
$('#b-over').onclick=()=>{stopTour();goOver();};
$('#b-labels').onclick=()=>toggle('#b-labels','nolabels',true);
$('#b-list').onclick=()=>toggle('#b-list','nolist',true);
function setTheme(light){
  document.body.classList.toggle('light',light); $('#b-theme').setAttribute('aria-pressed',String(light));
  kit.setTheme(light);
  cityU.uLo.value.setRGB(...(light?[.33,.6,.95]:[.012,.05,.115])); cityU.uHi.value.setRGB(...(light?[.82,.92,1]:[.05,.2,.4]));
  try{localStorage.setItem('astana-theme',light?'light':'dark');}catch(e){}
}
let saved='dark'; try{saved=localStorage.getItem('astana-theme')||'dark';}catch(e){}
setTheme(location.hash.includes('light')||saved==='light');
$('#b-theme').onclick=()=>setTheme(!document.body.classList.contains('light'));
$('#b-vert').onclick=()=>toggle('#b-vert','vertical');
$('#speed').oninput=e=>$('#speedv').textContent=String(e.target.value).replace('.',',')+' с';
addEventListener('keydown',e=>{
  if(e.target.tagName==='INPUT'||e.metaKey||e.ctrlKey) return;
  const k=e.key.toLowerCase();
  if(k>='1'&&k<='9'&&+k<=VENUES.length){stopTour();goVenue(+k-1);}
  else if(k==='0'||k==='escape'){stopTour();goOver();}
  else if(k===' '){e.preventDefault();$('#b-tour').click();}
  else if(k==='h'||k==='р') document.body.classList.toggle('clean');
  else if(k==='l'||k==='д') $('#b-labels').click();
  else if(k==='v'||k==='м') $('#b-vert').click();
  else if(k==='s'||k==='ы') $('#b-list').click();
  else if(k==='t'||k==='е') $('#b-theme').click();
});
function resize(){const w=stage.clientWidth,h=stage.clientHeight;renderer.setSize(w,h,false);camera.aspect=w/h;camera.fov=w<h?52:38;camera.updateProjectionMatrix();}
new ResizeObserver(resize).observe(stage); resize();

/* ---------- loop ---------- */
const tmp=new THREE.Vector3();
function frame(now){
  stepFlight(now); if(!flight) controls.update();
  const t=now/1000, far=camera.position.distanceTo(controls.target);
  rings.forEach(r=>{const p=(t*.45+r.userData.ph)%1;r.scale.setScalar(r.userData.r*(.7+p*1.1));r.material.opacity=.85*(1-p);});
  const w=stage.clientWidth,h=stage.clientHeight;
  VENUES.forEach((v,i)=>{
    v.beam.material.opacity=THREE.MathUtils.clamp((far-900)/5000,0,.26);
    tmp.set(v.pos.x,v.h+(far>2500?120:14),v.pos.z).project(camera);
    const vis=tmp.z<1&&Math.abs(tmp.x)<1.1&&Math.abs(tmp.y)<1.1&&(far>1500||active===i||active<0);
    v.el.style.opacity=vis?1:0;
    if(vis) v.el.style.transform=`translate(${(tmp.x*.5+.5)*w}px,${(-tmp.y*.5+.5)*h}px) translate(-50%,-100%)`;
  });
  renderer.render(scene,camera); requestAnimationFrame(frame);
}
const c0=location.hash.match(/^#v([1-9])/);
if(c0&&VENUES[+c0[1]-1]){const v=venueView(VENUES[+c0[1]-1]);place(v.t,v.dist,v.az,v.pol);mark(+c0[1]-1);} else place(OVER.t,OVER.dist,OVER.az,OVER.pol);
controls.update(); $('#load').hidden=true; requestAnimationFrame(frame);
})();
