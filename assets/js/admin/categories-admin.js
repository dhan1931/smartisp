(() => {
const $ = selector => document.querySelector(selector);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const slug = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,80);
const splitLines = value => [...new Set(String(value).split(/[\n,]+/).map(item=>item.trim()).filter(Boolean))];
const safeImage = url => Boolean(url) && (/^https:\/\//i.test(url) || /^\/(?:uploads\/(?:categories|products|campaigns|brands)\/|api\/auth\/serve-upload\?)/.test(url)) && !/[\x00-\x1f\\]/.test(url) && !url.includes('..');
let categories = [], families = [], editingId = '', pendingUploads = 0;
async function api(action, {method='GET', body} = {}) {
  const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
  const headers = token ? {Authorization:`Bearer ${token}`,'X-Admin-Token':token} : {};
  if (body && !(body instanceof FormData)) {
    headers['Content-Type']='application/json';
    const binary = Array.from(new TextEncoder().encode(JSON.stringify(body)),byte=>String.fromCharCode(byte)).join('');
    body=JSON.stringify({payload:btoa(binary)});
  }
  const [name,query='']=action.split('?');
  const response = await fetch(`/api/router.php?action=${encodeURIComponent(name)}${query?'&'+query:''}`, {method,body,headers,credentials:'include'});
  const data = await response.json().catch(()=>({error:'El servidor no devolvió una respuesta válida.'}));
  if(!response.ok) { const error=new Error(data.error || 'No se pudo completar la operación.'); error.status=response.status; throw error; }
  if(data.token) localStorage.setItem('smartisp.adminToken',data.token);
  return data;
}
function notice(message, type='success', target=$('#status')) { target.textContent=message; target.className=`notice ${type}`; target.hidden=false; }
function visual(url, icon='package') { return safeImage(url) ? `<img src="${escapeHtml(url)}" alt="" loading="lazy">` : `<i data-lucide="${escapeHtml(/^[a-z0-9-]+$/.test(icon)?icon:'package')}"></i>`; }
function render() {
  $('#categoryCount').textContent=categories.length; $('#familyCount').textContent=families.length;
  const q=$('#search').value.trim().toLocaleLowerCase(), family=$('#familyFilter').value;
  const rows=categories.filter(cat=>(family==='all'||(family==='ungrouped'?!cat.macroGroupId:cat.macroGroupId===family)) && `${cat.name} ${(cat.subcategories||[]).join(' ')}`.toLocaleLowerCase().includes(q));
  $('#resultCount').textContent=`${rows.length} de ${categories.length} categorías`;
  $('#categoryList').innerHTML=rows.map(cat=>{const parent=categories.find(item=>item.id===cat.parentCategoryId);const childCount=categories.filter(item=>item.parentCategoryId===cat.id).length;return `<article class="category-row"><div class="category-visual">${visual(cat.iconImageUrl,cat.icon)}</div><div class="category-info"><h3>${escapeHtml(cat.name)}</h3><p>${escapeHtml(families.find(group=>group.id===cat.macroGroupId)?.name || 'Sin familia')}${parent?` · Subcategoría de ${escapeHtml(parent.name)}`:''}</p><p class="category-meta"><span>${Number(cat.productCount||0)} productos</span>${childCount?`<span>${childCount} subcategoría${childCount===1?'':'s'}</span>`:''}<span>${(cat.subcategories||[]).length} etiquetas</span>${cat.iconImageUrl?'<span>Imagen propia</span>':''}${cat.bannerImageUrl?'<span>Con banner</span>':''}</p></div><div class="category-actions"><button class="button secondary" data-edit="${escapeHtml(cat.id)}">Editar</button><a class="button secondary" href="/editor-catalogo.html?category=${encodeURIComponent(cat.name)}">Productos ↗</a><details><summary class="button secondary" aria-label="Más opciones para ${escapeHtml(cat.name)}">⋯</summary><div class="more-actions"><button data-move="${escapeHtml(cat.id)}">Mover sus productos</button><button class="danger" data-delete="${escapeHtml(cat.id)}">Eliminar categoría</button></div></details></div></article>`;}).join('') || '<p class="empty">No hay categorías que coincidan. Prueba otra búsqueda o crea una categoría.</p>';
  $('#familyList').innerHTML=families.map(group=>`<article class="category-row"><div class="category-visual">${visual(group.imageUrl,group.icon)}</div><div class="category-info"><h3>${escapeHtml(group.name)}</h3><p>${categories.filter(cat=>cat.macroGroupId===group.id).length} categorías · ${group.visible?'Visible en tienda':'Oculta en tienda'} · Orden ${Number(group.sortOrder||0)}</p></div><div class="category-actions"><button class="button secondary" data-edit-family="${escapeHtml(group.id)}">Editar familia</button></div></article>`).join('') || '<p class="empty">Crea una familia para organizar tus categorías.</p>';
  window.lucide?.createIcons();
}
async function load() {
  const data=await api('categories'); categories=data.categories||[]; families=data.macroGroups||[];
  const value=$('#familyFilter').value;
  $('#familyFilter').innerHTML='<option value="all">Todas las familias</option>'+families.map(group=>`<option value="${escapeHtml(group.id)}">${escapeHtml(group.name)}</option>`).join('')+'<option value="ungrouped">Sin familia</option>';
  $('#familyFilter').value=[...$('#familyFilter').options].some(option=>option.value===value)?value:'all'; render();
}
function preview(form,name) {
  const element=form.querySelector(`[data-preview="${name}"]`), url=form.elements[name]?.value.trim(); if(!element)return;
  element.replaceChildren(); if(!safeImage(url)){element.textContent='Sin imagen';return;}
  const img=new Image(); img.src=url; img.alt=''; img.onerror=()=>{element.textContent='No se pudo cargar la imagen';}; element.append(img);
}
function descendantIds(id) {
  const result=new Set();
  const walk=parentId=>{categories.filter(item=>item.parentCategoryId===parentId).forEach(child=>{if(!result.has(child.id)){result.add(child.id);walk(child.id);}});};
  walk(id);
  return result;
}
function editCategory(id='') {
  editingId=id; const cat=categories.find(item=>item.id===id)||{}, form=$('#categoryForm'); form.reset();
  form.querySelectorAll('details').forEach(details=>details.open=false);
  form.elements.macroGroupId.innerHTML='<option value="">Sin familia</option>'+families.map(group=>`<option value="${escapeHtml(group.id)}">${escapeHtml(group.name)}</option>`).join('');
  const blocked=id?descendantIds(id):new Set();
  form.elements.parentCategoryId.innerHTML='<option value="">(Ninguna -- categoría principal)</option>'+categories.filter(item=>item.id!==id&&!blocked.has(item.id)).map(item=>`<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
  for(const field of ['id','name','description','macroGroupId','parentCategoryId','iconImageUrl','bannerImageUrl','bannerAlt'])form.elements[field].value=cat[field]||'';
  form.elements.icon.value=cat.icon||'package';
  if(!form.elements.icon.value){const option=new Option(cat.icon,cat.icon);form.elements.icon.add(option);form.elements.icon.value=cat.icon;}
  form.elements.subcategories.value=(cat.subcategories||[]).join('\n'); form.elements.keywords.value=(cat.keywords||[]).join('\n'); form.elements.id.readOnly=Boolean(id);
  $('#categoryTitle').textContent=id?`Editar ${cat.name}`:'Nueva categoría'; $('#categoryError').hidden=true; $('#uploadStatus').textContent='';
  ['iconImageUrl','bannerImageUrl'].forEach(field=>preview(form,field)); $('#categoryDialog').showModal();
}
function editFamily(id='') {
  const group=families.find(item=>item.id===id)||{}, form=$('#familyForm'); form.reset();
  for(const field of ['id','name','icon','imageUrl'])form.elements[field].value=group[field]|| (field==='icon'?'package':'');
  form.elements.sortOrder.value=group.sortOrder||0;form.elements.visible.checked=group.visible!==false;
  $('#familyTitle').textContent=id?`Editar ${group.name}`:'Nueva familia';$('#familyError').hidden=true;$('#familyUploadStatus').textContent='';preview(form,'imageUrl');$('#familyDialog').showModal();
}
$('#categoryForm [name="name"]').addEventListener('input',event=>{if(!editingId)$('#categoryForm [name="id"]').value=slug(event.target.value);});
for(const form of [$('#categoryForm'),$('#familyForm')]) {
  form.addEventListener('input',event=>{if(['iconImageUrl','bannerImageUrl','imageUrl'].includes(event.target.name))preview(form,event.target.name);});
  form.addEventListener('change',async event=>{
    const field=event.target.dataset.upload,file=event.target.files?.[0];if(!field||!file)return;
    const status=form.querySelector('[id$="UploadStatus"],#uploadStatus');
    if(file.size>8*1024*1024){status.textContent='La imagen supera el límite de 8 MB.';return;}
    pendingUploads++;$('#saveCategory').disabled=true;$('#saveFamily').disabled=true; status.textContent='Subiendo imagen…';
    try {const body=new FormData();body.append('image',file);const result=await api('upload-merch-image?category=categories',{method:'POST',body});form.elements[field].value=result.url;preview(form,field);status.textContent='Imagen lista. Guarda para aplicar el cambio.';}
    catch(error){status.textContent=error.message;}
    finally{pendingUploads--;$('#saveCategory').disabled=pendingUploads>0;$('#saveFamily').disabled=pendingUploads>0;event.target.value='';}
  });
}
async function submit(form,errorTarget,action,body,success) {
  const button=form.querySelector('[type="submit"]');button.disabled=true;errorTarget.hidden=true;
  try {await api(action,{method:'POST',body});form.closest('dialog').close();notice(success);await load();}
  catch(error){notice(error.message,'error',form.closest('dialog').open?errorTarget:$('#status'));}
  finally{button.disabled=false;}
}
$('#categoryForm').addEventListener('submit',async event=>{
  event.preventDefault();if(pendingUploads)return;const form=event.target;
  const category=Object.fromEntries(new FormData(form));category.subcategories=splitLines(category.subcategories);category.keywords=splitLines(category.keywords);
  await submit(form,$('#categoryError'),'categories',{category},'Categoría guardada.');
});
$('#familyForm').addEventListener('submit',async event=>{
  event.preventDefault();if(pendingUploads)return;const form=event.target,body=Object.fromEntries(new FormData(form));body.visible=form.elements.visible.checked;body.sortOrder=Number(body.sortOrder);
  await submit(form,$('#familyError'),'macro-groups',body,'Familia guardada.');
});
$('#moveForm').addEventListener('submit',async event=>{event.preventDefault();await submit(event.target,$('#moveError'),'categories-reassign',Object.fromEntries(new FormData(event.target)),'Productos movidos.');});
document.addEventListener('click',async event=>{
  const button=event.target.closest('button,[data-clear]');if(!button)return;
  if(button.hasAttribute('data-close')) {if(!pendingUploads)button.closest('dialog').close();return;}
  if(button.dataset.clear){const form=button.closest('form');form.elements[button.dataset.clear].value='';preview(form,button.dataset.clear);return;}
  if(button.dataset.edit){editCategory(button.dataset.edit);return;}
  if(button.dataset.editFamily){editFamily(button.dataset.editFamily);return;}
  if(button.dataset.view){document.querySelectorAll('[data-view]').forEach(tab=>{const active=tab===button;tab.classList.toggle('selected',active);tab.setAttribute('aria-pressed',String(active));});$('#categoriesView').hidden=button.dataset.view!=='categories';$('#familiesView').hidden=button.dataset.view!=='families';return;}
  if(button.dataset.move){
    const cat=categories.find(item=>item.id===button.dataset.move),form=$('#moveForm');form.elements.fromCategory.value=cat.name;
    form.elements.toCategory.innerHTML=categories.filter(item=>item.id!==cat.id).map(item=>`<option value="${escapeHtml(item.name)}">${escapeHtml(item.name)}</option>`).join('');
    $('#moveDescription').textContent=`Mover todos los productos de ${cat.name} a otra categoría.`;$('#moveError').hidden=true;$('#moveDialog').showModal();return;
  }
  if(button.dataset.delete){const cat=categories.find(item=>item.id===button.dataset.delete);if(!confirm(`¿Eliminar ${cat.name}? Solo se puede eliminar si no tiene productos.`))return;try{await api(`categories?id=${encodeURIComponent(cat.id)}`,{method:'DELETE'});await load();notice('Categoría eliminada.');}catch(error){notice(error.message,'error');}}
});
$('#newCategory').addEventListener('click',()=>editCategory());$('#newFamily').addEventListener('click',()=>editFamily());
$('#search').addEventListener('input',render);$('#familyFilter').addEventListener('change',render);$('#reload').addEventListener('click',()=>load().catch(error=>notice(error.message,'error')));
for(const dialog of document.querySelectorAll('dialog'))dialog.addEventListener('cancel',event=>{if(pendingUploads)event.preventDefault();});
(async()=>{
  try {const {user}=await api('me');if(!['admin','administrator','administrador'].includes(String(user?.role).trim().toLowerCase()))throw new Error('Tu cuenta no tiene acceso a la administración.');await load();$('#app').hidden=false;$('#status').hidden=true;}
  catch(error){if(error.status===401)location.assign(`/login.html?admin=1&redirect=${encodeURIComponent('/categorias.html')}`);else notice(error.message,'error');}
})();
})();
