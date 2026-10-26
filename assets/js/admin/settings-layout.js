(function(){
  const $=selector=>document.querySelector(selector);
  const content=$('#contentForm'),panel=$('#adminPanel');if(!content||!panel)return;
  const grid=panel.querySelector('.grid');grid.classList.add('settings-panels');
  const appearance=content.closest('.panel'),catalog=$('#visibilityForm').closest('.panel'),mail=$('#notificationsForm').closest('.panel');
  appearance.classList.add('settings-content-panel');
  const toolbar=panel.querySelector('.toolbar');
  const intro=document.createElement('p');intro.className='settings-intro';intro.textContent='Elige qué necesitas cambiar. Cada sección guarda únicamente sus ajustes.';toolbar.insertAdjacentElement('afterend',intro);
  const nav=document.createElement('nav');nav.className='tabs settings-tabs';nav.setAttribute('aria-label','Secciones de configuración');
  nav.innerHTML=[['brand','Marca y logo'],['contact','Contacto'],['catalog','Catálogo'],['mail','Correo']].map(([key,title])=>`<button type="button" class="tab" data-settings-tab="${key}" aria-label="${title}" aria-pressed="false">${title}</button>`).join('');intro.insertAdjacentElement('afterend',nav);
  const brand=document.createElement('section'),contact=document.createElement('section'),legacy=document.createElement('details');
  brand.dataset.settingsGroup='brand';contact.dataset.settingsGroup='contact';legacy.className='settings-legacy';
  brand.append(content.querySelector('.logo-customizer-card'));
  contact.innerHTML='<p>Datos que se muestran en el pie de página.</p>';
  [...content.children].filter(element=>element.tagName==='LABEL'&&element.querySelector('[name^="footer_"]')).forEach(element=>contact.append(element));
  legacy.innerHTML='<summary>Ajustes de la portada anterior</summary><p>Los banners actuales se administran en <a href="/campanas.html">Campañas</a> y las categorías destacadas en <a href="/portada.html">Portada</a>.</p>';
  legacy.append(content.querySelector('.design-preview'),content.querySelector('.design-controls'));
  const legacySection=document.createElement('section');legacySection.dataset.settingsGroup='legacy';legacySection.append(legacy);
  brand.append(legacySection);
  const actions=content.querySelector('.form-actions'),message=$('#contentMessage');
  actions.classList.add('settings-savebar');actions.querySelector('[type="submit"]').textContent='Guardar logo';
  $('#clearContentButton').hidden=true;
  const feedback=document.createElement('span');feedback.className='settings-draft';feedback.setAttribute('role','status');feedback.textContent='Sin cambios pendientes';actions.append(feedback);
  content.prepend(contact);content.prepend(brand);content.append(message,actions);
  appearance.querySelector('.section-heading h3').textContent='Marca y logo';
  $('#resetContentButton').textContent='Usar predeterminado';
  catalog.querySelector('h3').textContent='Catálogo público';
  mail.querySelector('h3').textContent='Correo y notificaciones';
  const mailHeading=mail.querySelector('.section-heading');
  mailHeading.querySelector('span')?.replaceChildren(document.createTextNode('Cuenta de envío y destinatario de las alertas de pedidos.'));
  $('#testEmailButton').textContent='Enviar correo de prueba';
  const drafts={brand:false,contact:false,catalog:false,mail:false},baselines={};let active='brand';
  const belongs=(key,group)=>group==='contact'?key.startsWith('footer_'):group==='brand'?/^logo_|^brand_name$|^primary_color$/.test(key):group==='legacy'?key.startsWith('hero_')||key==='primary_color':true;
  window.smartispSettingsPayload=(form,values)=>form===content?Object.fromEntries(Object.entries(values).filter(([key])=>belongs(key,active))):values;
  function refresh(){
    nav.querySelectorAll('button').forEach(button=>{button.classList.toggle('selected',button.dataset.settingsTab===active);button.setAttribute('aria-pressed',String(button.dataset.settingsTab===active));button.dataset.dirty=String(drafts[button.dataset.settingsTab]);});
    feedback.textContent=drafts[active]?'Cambios sin guardar':'Sin cambios pendientes';
  }
  function select(group){
    active=group;appearance.hidden=!['brand','contact'].includes(group);catalog.hidden=group!=='catalog';mail.hidden=group!=='mail';brand.hidden=group!=='brand';contact.hidden=group!=='contact';
    content.dataset.settingsSection=group;
    appearance.querySelector('.section-heading h3').textContent=group==='contact'?'Contacto de la tienda':'Marca y logo';
    actions.querySelector('[type="submit"]').textContent=group==='contact'?'Guardar contacto':'Guardar logo';
    content.querySelectorAll('[name]').forEach(input=>input.disabled=!belongs(input.name,group));
    $('#contentMessage').classList.remove('show');refresh();
  }
  nav.addEventListener('click',event=>{const button=event.target.closest('[data-settings-tab]');if(button)select(button.dataset.settingsTab);});
  legacy.addEventListener('toggle',()=>{legacy.querySelectorAll('[name]').forEach(input=>input.disabled=true);});
  const reset=$('#resetContentButton');reset.title='Prepara los valores predeterminados de esta sección. Revisa y guarda para aplicar.';
  const forms={brand:content,contact:content,catalog:$('#visibilityForm'),mail:$('#notificationsForm')};
  // Pendiente solo si los valores difieren de los cargados o guardados, no por haber tocado un campo.
  const snapshot=group=>JSON.stringify([...forms[group].querySelectorAll('[name]')].filter(input=>input.type!=='file'&&(forms[group]!==content||belongs(input.name,group))).map(input=>[input.name,input.type==='checkbox'||input.type==='radio'?input.checked:String(input.value??'').trim()]));
  const rebase=group=>{baselines[group]=snapshot(group);drafts[group]=false;};
  const check=group=>{if(group in baselines)drafts[group]=snapshot(group)!==baselines[group];refresh();};
  document.addEventListener('settings-loaded',()=>{Object.keys(forms).forEach(rebase);refresh();});
  for(const form of new Set(Object.values(forms))){
    const groups=()=>form===content?['brand','contact']:[form.id==='visibilityForm'?'catalog':'mail'];
    for(const type of ['input','change','image-applied'])form.addEventListener(type,()=>groups().forEach(check));
    form.addEventListener('settings-saved',()=>{form===content?rebase(active):groups().forEach(rebase);refresh();});
  }
  window.addEventListener('beforeunload',event=>{if(Object.values(drafts).some(Boolean)){event.preventDefault();event.returnValue='';}});
  const destination=$('#notificationsForm [name="admin_email"]');destination.closest('label').firstChild.textContent='Recibir alertas en';
  const help=destination.closest('div').querySelector('small');if(help)help.textContent='Aquí llegan las alertas de pedidos. Este correo no cambia los permisos de acceso al admin.';
  const provider=$('#notificationsForm [name="smtp_provider"]');
  if(provider){provider.closest('label').firstChild.textContent='Enviar correos con';[...provider.options].forEach(option=>{option.textContent=({gmail:'Gmail',hostinger:'Hostinger',custom:'Otro servidor SMTP',smtp:'Otro servidor SMTP',resend:'Resend'})[option.value]||option.textContent;});}
  for(const id of ['gmailConfigSection','hostingerConfigSection','resendSection']){
    const section=document.getElementById(id);if(!section)continue;
    const paragraphs=[...section.children].filter(element=>element.tagName==='P');
    if(!paragraphs.length)continue;const details=document.createElement('details');details.className='settings-mail-help';details.innerHTML='<summary>Cómo configurar esta cuenta</summary>';paragraphs[0].before(details);paragraphs.forEach(paragraph=>details.append(paragraph));
  }
  const heading=document.querySelector('body>.top h1');if(heading)heading.textContent='Configuración';
  const topLink=document.querySelector('body>.top a');if(topLink)topLink.textContent='Ver tienda';
  const title=toolbar.querySelector('h2');if(title)title.textContent='Ajustes de la tienda';
  $('#tabLogoLightBtn').textContent='En tienda';$('#tabLogoDarkBtn').textContent='Pie de página';$('#tabLogoTextBtn').textContent='Solo texto';
  select('brand');
})();
