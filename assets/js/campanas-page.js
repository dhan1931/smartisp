  (() => {
    const $ = selector => document.querySelector(selector);
    // Agregar una pagina nueva al editor = agregar una entrada aca + al whitelist del backend
    // (STOREFRONT_CAMPAIGN_ZONES en api/handlers/campaigns.php) + wirear el fetch/render del
    // hero en esa pagina publica (ver loadStoreBrands()/hero fetch en nosotros.html como
    // ejemplo del patron minimo: fetch a /api/auth/store-campaigns?zone=<code>, renderizar si
    // hay slides). No hace falta tocar el modelo de datos ni el resto del editor.
    const ZONE_CONFIG = {
      'store-home': { isPrimary:true, defaultMode:'carousel', defaultLayoutWidth:'full', restrictCarouselSingle:false, defaultName:'Portada de la tienda', title:'Portada de la tienda', nameLabel:'Nombre de campaña', slidesHeading:'Banners de portada', previewLabel:'Vista previa de portada' },
      'store-secondary': { isPrimary:false, defaultMode:'split', defaultLayoutWidth:'full', restrictCarouselSingle:true, defaultName:'Banners secundarios', title:'Banners secundarios', nameLabel:'Nombre de sección', slidesHeading:'Banners secundarios', previewLabel:'Vista previa de banners secundarios' },
      'categorias': { isPrimary:false, defaultMode:'single', defaultLayoutWidth:'contained', restrictCarouselSingle:false, defaultName:'Banner — Categorías', title:'Página Categorías', nameLabel:'Nombre de sección', slidesHeading:'Banner de Categorías', previewLabel:'Vista previa del banner de Categorías' },
      'nosotros': { isPrimary:false, defaultMode:'single', defaultLayoutWidth:'contained', restrictCarouselSingle:false, defaultName:'Banner — Nosotros', title:'Página Nosotros', nameLabel:'Nombre de sección', slidesHeading:'Banner de Nosotros', previewLabel:'Vista previa del banner de Nosotros' },
      'servicios': { isPrimary:false, defaultMode:'single', defaultLayoutWidth:'contained', restrictCarouselSingle:false, defaultName:'Banner — Servicios', title:'Página Servicios', nameLabel:'Nombre de sección', slidesHeading:'Banner de Servicios', previewLabel:'Vista previa del banner de Servicios' },
      'servicios-proyectos': { isPrimary:false, defaultMode:'cards', defaultLayoutWidth:'contained', restrictCarouselSingle:true, defaultName:'Proyectos realizados', title:'Proyectos realizados', nameLabel:'Nombre de sección', slidesHeading:'Proyectos realizados', previewLabel:'Vista previa de proyectos realizados' },
      'servicios-especializados': { isPrimary:false, defaultMode:'cards', defaultLayoutWidth:'contained', restrictCarouselSingle:true, defaultName:'Servicios especializados', title:'Servicios especializados', nameLabel:'Nombre de sección', slidesHeading:'Servicios especializados', previewLabel:'Vista previa de servicios especializados' },
    };
    const zoneConfig = () => ZONE_CONFIG[activeZone] || ZONE_CONFIG['store-secondary'];
    const state = { campaign: { name:'Portada de la tienda',rotation_seconds:7,is_active:true,display_mode:'carousel',layout_width:'contained',hero_height_pct:100,mobile_swipe:false,starts_at:'',ends_at:'' }, slides:[], chips:[] };
    let activeZone = 'store-home';
    let previewSlideIndex = 0;
    let slideKeyCounter = 0;
    let preferredExpandedKey = '';
    let lastPublished = null;
    let isDirty = false;
    let draftTimer = 0;
    const draftKey = () => `smartisp.store-campaign-draft.v2.${activeZone}`;
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    // Reemplaza confirm() nativo (se ve feo, es del navegador) por un <dialog> propio con el
    // mismo estilo del resto del editor. Devuelve una promesa: true si el admin confirma.
    function confirmDialog(text, { okText = 'Continuar', cancelText = 'Cancelar', danger = false } = {}) {
      return new Promise(resolve => {
        const dialog = document.createElement('dialog');
        dialog.className = 'confirm-dialog';
        dialog.innerHTML = `<form method="dialog"><p>${escapeHtml(text)}</p><div class="confirm-actions"><button value="cancel" class="button secondary" type="submit">${escapeHtml(cancelText)}</button><button value="ok" class="button${danger ? ' danger' : ''}" type="submit">${escapeHtml(okText)}</button></div></form>`;
        document.body.append(dialog);
        dialog.addEventListener('close', () => { resolve(dialog.returnValue === 'ok'); dialog.remove(); }, { once: true });
        dialog.showModal();
      });
    }
    const isAdmin = user => ['admin','administrator','administrador'].includes(String(user?.role || '').trim().toLowerCase());
    const modeValue = () => document.querySelector('input[name="displayMode"]:checked')?.value || 'carousel';
    const layoutWidthValue = () => document.querySelector('input[name="layoutWidth"]:checked')?.value || 'contained';
    const localDate = value => {
      if (!value) return '';
      const text = String(value);
      if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(text)) return text;
      const date = new Date(`${text.replace(' ', 'T')}Z`);
      return Number.isNaN(date.valueOf()) ? '' : new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Guayaquil',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(date).replace(' ','T');
    };
    const encode = value => { const bytes = new TextEncoder().encode(value); let binary=''; bytes.forEach(byte => binary += String.fromCharCode(byte)); return btoa(binary); };
    async function api(action, options={}) {
      const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
      const headers = { ...(options.headers || {}) };
      if (token) { headers.Authorization = `Bearer ${token}`; headers['X-Admin-Token'] = token; }
      let body = options.body;
      if (typeof body === 'string' && options.method === 'POST') { headers['Content-Type']='application/json'; body=JSON.stringify({payload:encode(body)}); }
      const [name,query='']=action.split('?');
      const response = await fetch(`/api/router.php?action=${encodeURIComponent(name)}${query?'&'+query:''}`, {credentials:'include',...options,headers,body});
      const text = await response.text(); let result={};
      try { result=text?JSON.parse(text):{}; } catch { result={error:'Respuesta no válida del servidor.'}; }
      if (!response.ok) { const error=new Error(result.error || 'No se pudo completar la operación.'); error.status=response.status; throw error; }
      if (result.token) localStorage.setItem('smartisp.adminToken',result.token);
      return result;
    }
    function message(text,error=false,target=null) {
      const targets=target?[target]:[$('#saveMessageTop'),$('#saveMessage')].filter(Boolean);
      targets.forEach(el=>{el.textContent=text;el.classList.toggle('error',error);el.classList.add('show');});
      if(error&&targets[0])targets[0].scrollIntoView({behavior:'smooth',block:'center'});
    }
    function showLogin() { $('#denied').classList.add('hidden'); $('#login').classList.remove('hidden'); }
    function showDenied() { $('#login').classList.add('hidden'); $('#denied').classList.remove('hidden'); }
    function showApp(user) { $('#login').classList.add('hidden'); $('#denied').classList.add('hidden'); $('#identity').textContent=`${user.name || ''} ${user.surname || ''} · ${user.email || ''}`; $('#app').classList.remove('hidden'); load(); }
    function previewBulletMarkup(style,color){
      if(style==='check')return `<i data-lucide="check" width="12" style="color:${color}"></i>`;
      if(style==='check-circle')return `<span class="preview-bullet-mark preview-bullet-check-circle" style="background:${color}"><i data-lucide="check"></i></span>`;
      return `<span class="preview-bullet-mark preview-bullet-${style}" style="background:${color}"></span>`;
    }
    function previewSubtitleHtml(slide){
      const text=String(slide.subtitle||'Descripción del anuncio');
      const style=['check','circle','square','check-circle'].includes(slide.bullet_style)?slide.bullet_style:'none';
      if(style==='none')return `<p>${escapeHtml(text)}</p>`;
      const color=/^#[0-9a-fA-F]{6}$/.test(slide.bullet_color||'')?slide.bullet_color:'#16a34a';
      const items=text.split('\n').map(line=>line.trim()).filter(Boolean);
      if(!items.length)return '';
      return `<ul class="preview-bullets">${items.map(item=>`<li>${previewBulletMarkup(style,color)}<span>${escapeHtml(item)}</span></li>`).join('')}</ul>`;
    }
    function previewTextColorStyle(slide){
      const title=/^#[0-9a-fA-F]{6}$/.test(slide.title_color||'')?`color:${slide.title_color};`:'';
      const titleLine2=/^#[0-9a-fA-F]{6}$/.test(slide.title_line2_color||'')?`color:${slide.title_line2_color};`:'';
      const subtitle=/^#[0-9a-fA-F]{6}$/.test(slide.subtitle_color||'')?`color:${slide.subtitle_color};`:'';
      const eyebrow=/^#[0-9a-fA-F]{6}$/.test(slide.eyebrow_color||'')?`color:${slide.eyebrow_color};`:'';
      return {title,titleLine2,subtitle,eyebrow};
    }
    const previewImageTimers=new Set();
    const previewImageTimeouts=new Set();
    function renderPreview() {
      previewImageTimers.forEach(clearInterval);previewImageTimers.clear();
      previewImageTimeouts.forEach(clearTimeout);previewImageTimeouts.clear();
      const heroHeightPct=Math.max(60,Math.min(100,Number(state.campaign.hero_height_pct)||100));
      document.documentElement.style.setProperty('--hero-ar',(240/heroHeightPct).toFixed(3));
      const mode=modeValue(), active=state.slides.filter(slide=>slide.is_active!==false), count={single:1,split:2,triple:3,grid:4}[mode]||active.length;
      const host=$('#preview');
      ['preview-carousel','preview-single','preview-split','preview-triple','preview-grid','preview-cards'].forEach(name=>host.classList.toggle(name, name===`preview-${mode}`));
      previewSlideIndex=active.length?previewSlideIndex%active.length:0;
      const slides=(mode==='carousel'||mode==='single')?active.slice(previewSlideIndex,previewSlideIndex+1):active.slice(0,count);
      host.classList.toggle('preview-secondary',activeZone==='store-secondary');
      $('#previewHint').textContent=activeZone==='store-secondary'?`${slides.length} banner${slides.length===1?'':'s'} · en teléfonos: ${mode==='cards'?'desliza horizontalmente para verlos':'uno debajo del otro'}.`:mode==='carousel'&&active.length>1?`Banner ${previewSlideIndex+1} de ${active.length} · cambia cada ${Number(state.campaign.rotation_seconds)||7} s en la tienda.`:`${slides.length} banner${slides.length===1?'':'s'} · formato ${mode}.`;
      const content=slides.map((slide,index)=>{
        const images=Array.isArray(slide.images)?slide.images:[];
        const primary=images.find(image=>image.is_primary)||images[0];
        const imageUrl=primary?.image_url||slide.image_url||'';
        const chipIcon=['sparkles','star','truck'].includes(slide.promo_chip_icon)?slide.promo_chip_icon:'tag';
        const imageWidth=Math.max(35,Math.min(65,Number(slide.image_width_pct??55)));
        const isSide=slide.card_layout==='side';
        const opacity=Math.max(isSide?0:.55,Math.max(0,Math.min(100,Number(slide.overlay_opacity??68)))/100);
        const focalX=Math.max(0,Math.min(100,Number(primary?.focal_x??50))),focalY=Math.max(0,Math.min(100,Number(primary?.focal_y??50))),zoom=Math.max(100,Math.min(180,Number(primary?.zoom_pct??100)))/100;
        let mediaStyle=`object-fit:${slide.image_fit==='cover'?'cover':'contain'};object-position:${focalX}% ${focalY}%;transform:scale(${zoom});transform-origin:${focalX}% ${focalY}%;opacity:${Math.max(0,Math.min(100,Number(slide.image_opacity??100)))/100}`;
        let overlayStyle=`opacity:${opacity}`;
        let copyStyle='';
        if(isSide){
          const textWidth=100-imageWidth;
          const overlayBandWidth=12;
          const overlayStart=Math.max(0,textWidth-overlayBandWidth/2);
          mediaStyle=`inset:0 0 0 auto;width:${imageWidth}%;object-position:center;${mediaStyle}`;
          overlayStyle=`inset:0 auto 0 ${overlayStart}%;width:${overlayBandWidth}%;${overlayStyle}`;
          const copyColor=/^#[0-9a-fA-F]{6}$/.test(slide.copy_background_color||'')?slide.copy_background_color:'#ffffff';
          copyStyle=`width:${textWidth}%;max-width:${textWidth}%;background:${copyColor}`;
        }else{
          mediaStyle=`inset:0;object-position:${focalX}% ${focalY}%;${mediaStyle}`;
        }
        const textColor=previewTextColorStyle(slide);
        return `<article class="preview-card${isSide?' is-layout-side':' is-layout-full'}"><img class="preview-media" src="${escapeHtml(imageUrl)}" alt="${escapeHtml(primary?.image_alt||slide.title||'') }" style="${mediaStyle}"><span class="preview-overlay" style="${overlayStyle}"></span>${slide.badge_label?`<span class="preview-badge ${escapeHtml(slide.badge_tone||'discount')}">${escapeHtml(slide.badge_label)}</span>`:''}${slide.promo_chip_label?`<span class="preview-promo"><i data-lucide="${chipIcon}"></i>${escapeHtml(slide.promo_chip_label)}</span>`:''}<div class="preview-copy" style="${copyStyle}"><small style="${textColor.eyebrow}">${escapeHtml(slide.eyebrow||'SMARTISP')}${slide.eyebrow_line2?`<br>${escapeHtml(slide.eyebrow_line2)}`:''}</small><h4><span style="${textColor.title}">${escapeHtml(slide.title||'Título del banner '+(previewSlideIndex+index+1))}</span>${slide.title_line2?`<br><span style="${textColor.titleLine2}">${escapeHtml(slide.title_line2)}</span>`:''}</h4><div class="preview-subtitle-wrap" style="${textColor.subtitle}">${previewSubtitleHtml(slide)}</div>${slide.button_visible===false?'':`<strong>${escapeHtml(slide.button_text||'Explorar')} →</strong>`}${slide.button2_visible&&slide.button2_text?` <strong class="preview-btn-outline">${escapeHtml(slide.button2_text)}</strong>`:''}</div></article>`;
      }).join('') || '<p class="muted">Añade y activa al menos una pieza para ver la portada.</p>';
      const controls=mode==='carousel'&&active.length>1?`<div class="preview-carousel-controls"><button class="icon-button" type="button" data-preview-step="-1" aria-label="Banner anterior">‹</button><span>${previewSlideIndex+1} / ${active.length}</span><button class="icon-button" type="button" data-preview-step="1" aria-label="Siguiente banner">›</button></div>`:'';
      host.innerHTML=content+controls;
      host.querySelectorAll('.preview-card').forEach((card,index)=>{
        const slide=slides[index];
        if(!slide)return;
        applyPreviewFontScale(card.querySelector('.preview-copy > small'),slide.eyebrow_size);
        applyPreviewFontScale(card.querySelector('.preview-copy h4'),slide.title_size);
        applyPreviewFontScale(card.querySelector('.preview-subtitle-wrap'),slide.subtitle_size);
        const images=Array.isArray(slide.images)?slide.images:[];
        if(images.length<2||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
        const image=card.querySelector('.preview-media');
        let imageIndex=Math.max(0,images.findIndex(item=>item.is_primary));
        const baseOpacity=image.style.opacity;
        const duration=Math.max(150,Math.min(2000,Number(slide.image_transition_ms)||1000));
        image.style.transition=`opacity ${duration/2}ms ease`;
        const timer=setInterval(()=>{
          imageIndex=(imageIndex+1)%images.length;
          image.style.opacity='0';
          const timeout=setTimeout(()=>{
            previewImageTimeouts.delete(timeout);
            const next=images[imageIndex];
            image.src=next.image_url;image.alt=next.image_alt||slide.title||'';
            const x=Math.max(0,Math.min(100,Number(next.focal_x??50)));
            const y=Math.max(0,Math.min(100,Number(next.focal_y??50)));
            image.style.objectPosition=`${x}% ${y}%`;
            image.style.transform=`scale(${Math.max(100,Math.min(180,Number(next.zoom_pct??100)))/100})`;
            image.style.transformOrigin=`${x}% ${y}%`;
            image.style.opacity=baseOpacity;
          },duration/2);
          previewImageTimeouts.add(timeout);
        },Math.max(duration+100,Math.max(2,Math.min(20,Number(slide.image_interval_seconds)||5))*1000));
        previewImageTimers.add(timer);
      });
      window.lucide?.createIcons();
    }
    const FONT_SIZE_LABELS={sm:'Pequeño',md:'Normal',lg:'Grande',xl:'Extra grande'};
    const FONT_SIZE_SCALE={sm:.8,md:1,lg:1.25,xl:1.5};
    function sizeOptions(selected) {
      const current=['sm','md','lg','xl'].includes(selected)?selected:'md';
      return Object.entries(FONT_SIZE_LABELS).map(([value,label])=>`<option value="${value}" ${current===value?'selected':''}>${label}</option>`).join('');
    }
    function applyPreviewFontScale(el,sizeKey) {
      if(!el)return;
      const scale=FONT_SIZE_SCALE[sizeKey];
      if(!scale||scale===1){el.style.fontSize='';return;}
      const current=parseFloat(getComputedStyle(el).fontSize)||16;
      el.style.fontSize=(current*scale).toFixed(1)+'px';
    }
    function renderSlides() {
      const wasRendered=$('#slides').childElementCount>0;
      const expandedKeys=new Set([...$('#slides').querySelectorAll('.slide details[open]')].map(details=>details.closest('.slide')?.dataset.editorKey).filter(Boolean));
      state.slides.forEach(slide=>{if(!slide._editorKey)slide._editorKey=String(slide.id||`local-${++slideKeyCounter}`);});
      if(preferredExpandedKey)expandedKeys.add(preferredExpandedKey);
      if(!wasRendered&&state.slides[0])expandedKeys.add(state.slides[0]._editorKey);
      if(wasRendered&&expandedKeys.size===0&&state.slides[0])expandedKeys.add(state.slides[0]._editorKey);
      $('#slides').innerHTML=state.slides.map((slide,index)=>{
        if(!Array.isArray(slide.images)) slide.images=slide.image_url?[{image_url:slide.image_url,image_alt:slide.image_alt||slide.title||'',is_primary:true}]:[];
        const primary=slide.images.find(image=>image.is_primary)||slide.images[0];
        slide.image_url=primary?.image_url||'';
        const images=slide.images.map((image,imageIndex)=>{
          const fx=Number(image.focal_x??50),fy=Number(image.focal_y??50),zoom=Number(image.zoom_pct??100);
          const f=cropFrameRect(fx,fy,zoom);
          return `<div class="gallery-item ${image.is_primary?'is-primary':''}" data-image="${imageIndex}"><img src="${escapeHtml(image.image_url)}" alt="${escapeHtml(image.image_alt||'') }" width="240" height="140" loading="lazy" style="object-position:${fx}% ${fy}%;transform:scale(${zoom/100});transform-origin:${fx}% ${fy}%"><strong>${escapeHtml(image.image_url.split('/').pop())}</strong><div class="gallery-actions"><button type="button" data-primary-image ${image.is_primary?'disabled':''}>${image.is_primary?'Portada':'Usar portada'}</button><button type="button" data-image-up ${imageIndex===0?'disabled':''} aria-label="Subir orden">↑</button><button type="button" data-image-down ${imageIndex===slide.images.length-1?'disabled':''} aria-label="Bajar orden">↓</button><button type="button" data-remove-image aria-label="Quitar imagen">×</button></div><details class="image-crop" open><summary>✂️ Ajustar encuadre (recorte)</summary><p class="crop-help">Arrastra el recuadro para mover el encuadre, o sus esquinas para acercar/alejar. También puedes usar los controles de abajo.</p><div class="crop-canvas" data-crop-canvas><div class="crop-canvas-media"><img data-focal-preview src="${escapeHtml(image.image_url)}" alt="" style="object-position:${fx}% ${fy}%;transform:scale(${zoom/100});transform-origin:${fx}% ${fy}%"></div><div class="crop-frame" data-crop-frame style="left:${f.left}%;top:${f.top}%;width:${f.size}%;height:${f.size}%"><div class="crop-handle" data-crop-handle data-corner="tl" style="left:0%;top:0%"></div><div class="crop-handle" data-crop-handle data-corner="tr" style="left:100%;top:0%"></div><div class="crop-handle" data-crop-handle data-corner="bl" style="left:0%;top:100%"></div><div class="crop-handle" data-crop-handle data-corner="br" style="left:100%;top:100%"></div></div><div class="crop-crosshair" data-crop-crosshair style="left:${fx}%;top:${fy}%"></div></div><label>Foco horizontal<div class="opacity-row"><input data-image-field="focal_x" type="range" min="0" max="100" value="${fx}"><output>${fx}%</output></div></label><label>Foco vertical<div class="opacity-row"><input data-image-field="focal_y" type="range" min="0" max="100" value="${fy}"><output>${fy}%</output></div></label><label>Zoom<div class="opacity-row"><input data-image-field="zoom_pct" type="range" min="100" max="180" value="${zoom}"><output>${zoom}%</output></div></label></details></div>`;
        }).join('');
        return `<article class="slide" data-index="${index}" data-editor-key="${escapeHtml(slide._editorKey)}"><details ${expandedKeys.has(slide._editorKey)?'open':''}><summary><span class="slide-number">${index+1}</span><span class="slide-summary-copy">${escapeHtml(slide.title||'Banner sin título')}<small>${slide.images.length} imagen${slide.images.length===1?'':'es'} · ${slide.is_active===false?'Borrador oculto':'Visible en tienda'}</small></span><span class="slide-summary-actions"><button class="icon-button" type="button" data-move="-1" aria-label="Mover banner arriba" ${index===0?'disabled':''}>↑</button><button class="icon-button" type="button" data-move="1" aria-label="Mover banner abajo" ${index===state.slides.length-1?'disabled':''}>↓</button><button class="icon-button" type="button" data-remove aria-label="Eliminar banner">×</button></span></summary><div class="slide-body"><div class="slide-fields">
          <label>Etiqueta (línea 1)<input data-field="eyebrow" maxlength="100" value="${escapeHtml(slide.eyebrow||'')}" placeholder="Conectividad y redes"></label><label>Etiqueta (línea 2, opcional)<input data-field="eyebrow_line2" maxlength="100" value="${escapeHtml(slide.eyebrow_line2||'')}" placeholder="Déjalo vacío para una sola línea"></label><label>Tamaño de la etiqueta<select data-field="eyebrow_size">${sizeOptions(slide.eyebrow_size)}</select></label>
          <label>Título — línea 1 (opcional)<input data-field="title" maxlength="180" value="${escapeHtml(slide.title||'')}" placeholder="Déjalo vacío para mostrar solo la foto"></label><label>Título — línea 2 (opcional)<input data-field="title_line2" maxlength="200" value="${escapeHtml(slide.title_line2||'')}" placeholder="Ej. tus proyectos"></label><label>Tamaño del título<select data-field="title_size">${sizeOptions(slide.title_size)}</select></label>
          <label class="full">Subtítulo<textarea data-field="subtitle" maxlength="500" rows="3" placeholder="Una línea de contexto, o una idea por línea si vas a usar viñetas abajo.">${escapeHtml(slide.subtitle||'')}</textarea><small class="help">Cada línea (Enter) se respeta tal cual. Con viñetas activadas, cada línea no vacía es un ítem de la lista.</small></label>
          <label>Tamaño del subtítulo<select data-field="subtitle_size">${sizeOptions(slide.subtitle_size)}</select></label><label>Viñetas del subtítulo<select data-field="bullet_style"><option value="none" ${!slide.bullet_style||slide.bullet_style==='none'?'selected':''}>Sin viñetas (párrafo normal)</option><option value="check" ${slide.bullet_style==='check'?'selected':''}>Check ✓</option><option value="check-circle" ${slide.bullet_style==='check-circle'?'selected':''}>Círculo con visto (verde por defecto)</option><option value="circle" ${slide.bullet_style==='circle'?'selected':''}>Círculo</option><option value="square" ${slide.bullet_style==='square'?'selected':''}>Cuadrado</option></select></label>
          <div class="color-fields"><label>Color de viñeta<input data-field="bullet_color" type="color" value="${/^#[0-9a-fA-F]{6}$/.test(slide.bullet_color||'')?slide.bullet_color:'#16a34a'}"></label><label>Color de etiqueta<input data-field="eyebrow_color" type="color" value="${/^#[0-9a-fA-F]{6}$/.test(slide.eyebrow_color||'')?slide.eyebrow_color:'#087fa5'}"><button type="button" class="link-reset" data-clear-color="eyebrow_color" ${slide.eyebrow_color?'':'hidden'}>Predeterminado</button></label><label>Color de título (línea 1)<input data-field="title_color" type="color" value="${/^#[0-9a-fA-F]{6}$/.test(slide.title_color||'')?slide.title_color:(slide.card_layout==='side'?'#102a43':'#ffffff')}"><button type="button" class="link-reset" data-clear-color="title_color" ${slide.title_color?'':'hidden'}>Predeterminado</button></label><label>Color de título (línea 2)<input data-field="title_line2_color" type="color" value="${/^#[0-9a-fA-F]{6}$/.test(slide.title_line2_color||'')?slide.title_line2_color:'#38bdf8'}"><button type="button" class="link-reset" data-clear-color="title_line2_color" ${slide.title_line2_color?'':'hidden'}>Predeterminado</button></label><label>Color de subtítulo<input data-field="subtitle_color" type="color" value="${/^#[0-9a-fA-F]{6}$/.test(slide.subtitle_color||'')?slide.subtitle_color:(slide.card_layout==='side'?'#102a43':'#ffffff')}"><button type="button" class="link-reset" data-clear-color="subtitle_color" ${slide.subtitle_color?'':'hidden'}>Predeterminado</button></label></div>
          <label class="check full"><input data-field="show_trust_stats" type="checkbox" ${slide.show_trust_stats?'checked':''}> Mostrar franja de confianza (Productos · Clientes · Proyectos ejecutados · Soporte)</label>
          <div class="trust-stats-fields${slide.show_trust_stats?'':' hidden'}"><label>Clientes — valor y etiqueta, una por línea (vacío = default de Configuración)<textarea data-field="trust_stats_clients_override" maxlength="100" rows="2" placeholder="+500${String.fromCharCode(10)}Clientes satisfechos">${escapeHtml(slide.trust_stats_clients_override||'')}</textarea></label><label>Proyectos ejecutados — valor y etiqueta (vacío = default)<textarea data-field="trust_stats_projects_override" maxlength="100" rows="2" placeholder="+100${String.fromCharCode(10)}Proyectos ejecutados">${escapeHtml(slide.trust_stats_projects_override||'')}</textarea></label><label>Soporte — valor y etiqueta (vacío = default)<textarea data-field="trust_stats_support_override" maxlength="100" rows="2" placeholder="Soporte${String.fromCharCode(10)}especializado">${escapeHtml(slide.trust_stats_support_override||'')}</textarea></label><small class="help full">Cada campo son 2 líneas: la primera es el número/valor grande, la segunda la etiqueta chica debajo (ej. "+500" / "Clientes satisfechos"). Productos se calcula solo, desde el catálogo real. Los defaults de Clientes/Proyectos/Soporte se configuran en Configuración.</small></div>
          <label>Texto del botón<input data-field="button_text" maxlength="60" value="${escapeHtml(slide.button_text||'Explorar')}"></label><label>Destino del banner<input data-field="target_url" maxlength="500" value="${escapeHtml(slide.target_url||'/tienda.html#catalogo')}" placeholder="/categoria/slug o /producto/id"></label><label class="check compact full"><input data-field="button_visible" type="checkbox" ${slide.button_visible!==false?'checked':''}> Mostrar botón en el banner</label>
          <label>Texto del segundo botón<input data-field="button2_text" maxlength="60" value="${escapeHtml(slide.button2_text||'')}" placeholder="Opcional, ej. Conoce más"></label><label>Destino del segundo botón<input data-field="button2_url" maxlength="500" value="${escapeHtml(slide.button2_url||'')}" placeholder="Si queda vacío, usa el mismo destino del banner"></label><label class="check compact full"><input data-field="button2_visible" type="checkbox" ${slide.button2_visible?'checked':''}> Mostrar segundo botón (borde, sin relleno) junto al primero</label>
          <div class="date-row"><label>Visible desde<input data-field="starts_at" type="datetime-local" value="${escapeHtml(localDate(slide.starts_at))}"></label><label>Visible hasta<input data-field="ends_at" type="datetime-local" value="${escapeHtml(localDate(slide.ends_at))}"></label></div>
          <label>Estilo de tarjeta<select data-field="card_layout"><option value="full" ${slide.card_layout!=='side'?'selected':''}>Imagen de fondo + texto encima</option><option value="side" ${slide.card_layout==='side'?'selected':''}>Imagen lateral + panel de texto</option></select></label><label>Color de fondo del texto<input data-field="copy_background_color" type="color" value="${/^#[0-9a-fA-F]{6}$/.test(slide.copy_background_color||'')?slide.copy_background_color:'#ffffff'}"><small class="help">Se aplica al panel de texto en el modo “Imagen lateral”.</small></label><label>Ajuste de imagen<select data-field="image_fit"><option value="contain" ${slide.image_fit!=='cover'?'selected':''}>Mostrar imagen completa</option><option value="cover" ${slide.image_fit==='cover'?'selected':''}>Llenar marco (puede recortar)</option></select><small class="help">Completa conserva toda la foto y puede dejar espacio libre. Llenar cubre el marco; usa foco y zoom por imagen para elegir el recorte.</small></label><label>Ancho del área de foto<div class="opacity-row"><input data-field="image_width_pct" type="range" min="35" max="65" value="${Math.max(35,Math.min(65,Number(slide.image_width_pct??55)))}"><output>${Math.max(35,Math.min(65,Number(slide.image_width_pct??55)))}%</output></div><small class="help">Solo cambia el espacio reservado en este banner; no afecta los demás.</small></label><label>Rotación de imágenes (s)<input data-field="image_interval_seconds" type="number" min="2" max="20" value="${Number(slide.image_interval_seconds||5)}"></label><label>Duración de la transición<div class="opacity-row"><input data-field="image_transition_ms" type="range" min="150" max="2000" step="50" value="${Math.max(150,Math.min(2000,Number(slide.image_transition_ms??1000)))}"><output>${(Math.max(150,Math.min(2000,Number(slide.image_transition_ms??1000)))/1000).toFixed(2)}s</output></div><small class="help">Tiempo total del fundido: de 0,15 a 2 segundos. La rotación controla cuánto permanece cada imagen.</small></label>
          <label>Opacidad de imagen<div class="opacity-row"><input data-field="image_opacity" type="range" min="0" max="100" value="${Number(slide.image_opacity??100)}"><output>${Number(slide.image_opacity??100)}%</output></div></label><label>Opacidad del degradado<div class="opacity-row"><input data-field="overlay_opacity" type="range" min="0" max="100" value="${Number(slide.overlay_opacity??68)}"><output>${Number(slide.overlay_opacity??68)}%</output></div><small class="help">En “Imagen de fondo + texto encima” se conserva un mínimo de 55% para mantener el contraste.</small></label>
          <div class="badge-fields"><label>Chip promocional en esquina<input data-field="badge_label" maxlength="60" value="${escapeHtml(slide.badge_label||'')}" placeholder="-20%, Nuevo, Oferta"></label><label>Estilo del chip<select data-field="badge_tone"><option value="discount" ${slide.badge_tone==='discount'||!slide.badge_tone?'selected':''}>Descuento</option><option value="new" ${slide.badge_tone==='new'?'selected':''}>Nuevo</option><option value="featured" ${slide.badge_tone==='featured'?'selected':''}>Destacado</option><option value="dark" ${slide.badge_tone==='dark'?'selected':''}>Oscuro</option></select></label></div>
          <div class="badge-fields"><label>Acceso promocional de este banner<input data-field="promo_chip_label" maxlength="60" value="${escapeHtml(slide.promo_chip_label||'')}" placeholder="Ver oferta, Explorar categoría"></label><label>Icono del acceso<select data-field="promo_chip_icon"><option value="tag" ${slide.promo_chip_icon==='tag'||!slide.promo_chip_icon?'selected':''}>Oferta</option><option value="sparkles" ${slide.promo_chip_icon==='sparkles'?'selected':''}>Novedad</option><option value="star" ${slide.promo_chip_icon==='star'?'selected':''}>Destacado</option><option value="truck" ${slide.promo_chip_icon==='truck'?'selected':''}>Envío</option></select></label><label class="full">Destino del acceso<input data-field="promo_chip_target_url" maxlength="500" value="${escapeHtml(slide.promo_chip_target_url||slide.target_url||'/tienda.html#catalogo')}" placeholder="/categoria/redes o /tienda.html?ofertas"></label></div>
          <label class="check full"><input data-field="is_active" type="checkbox" ${slide.is_active!==false?'checked':''}> Visible en tienda</label>
        </div><div class="gallery"><div class="gallery-head"><strong>Galería del banner · imagen de portada marcada</strong><label class="button secondary">Añadir imágenes<input data-upload type="file" accept="image/jpeg,image/png,image/webp" multiple hidden></label></div><p class="help">JPG, PNG o WebP; hasta 8 MB y 40 megapíxeles por imagen. Puedes ordenar y elegir la inicial.</p><div class="gallery-list">${images||'<p class="gallery-empty">Sin imágenes todavía. Sube una o más para completar el banner.</p>'}</div></div></div></details></article>`;
      }).join('') || '<p class="muted">Todavía no hay banners. Añade el primero para preparar la portada.</p>';
      preferredExpandedKey='';
      window.lucide?.createIcons(); renderPreview();
    }
    function renderChips() {
      $('#chips').innerHTML=state.chips.map((chip,index)=>`<div class="chip-row" data-chip="${index}"><input data-field="label" maxlength="60" aria-label="Texto del acceso" placeholder="Ofertas" value="${escapeHtml(chip.label||'')}"><select data-field="icon" aria-label="Icono"><option value="tag" ${chip.icon==='tag'?'selected':''}>Etiqueta</option><option value="truck" ${chip.icon==='truck'?'selected':''}>Envío</option><option value="sparkles" ${chip.icon==='sparkles'?'selected':''}>Nuevo</option><option value="star" ${chip.icon==='star'?'selected':''}>Destacado</option><option value="box" ${chip.icon==='box'?'selected':''}>Combo</option></select><input data-field="target_url" maxlength="500" aria-label="Enlace" placeholder="/tienda.html#catalogo" value="${escapeHtml(chip.target_url||'')}"><button class="icon-button" data-remove-chip type="button" aria-label="Quitar acceso"><i data-lucide="trash-2"></i></button><label class="check" style="grid-column:1/-1"><input data-field="is_active" type="checkbox" ${chip.is_active!==false?'checked':''}> Visible</label></div>`).join('') || '<p class="muted">No hay accesos promocionales configurados.</p>'; window.lucide?.createIcons();
    }
    function snapshot() { return JSON.parse(JSON.stringify(state)); }
    function fillForm() {
      $('#campaignName').value=state.campaign.name; $('#rotation').value=state.campaign.rotation_seconds; $('#active').checked=!!state.campaign.is_active; $('#mobileSwipe').checked=!!state.campaign.mobile_swipe;
      $('#campaignStarts').value=localDate(state.campaign.starts_at); $('#campaignEnds').value=localDate(state.campaign.ends_at);
      const heroHeightPct=Math.max(60,Math.min(100,Number(state.campaign.hero_height_pct)||100));
      $('#heroHeightPct').value=heroHeightPct; $('#heroHeightPct').nextElementSibling.value=`${heroHeightPct}%`;
      document.querySelectorAll('input[name="displayMode"]').forEach(input=>input.checked=input.value===state.campaign.display_mode);
      document.querySelectorAll('input[name="layoutWidth"]').forEach(input=>input.checked=input.value===state.campaign.layout_width);
      renderSlides(); renderChips();
    }
    function collectForm() {
      state.campaign={...state.campaign,name:$('#campaignName').value.trim(),rotation_seconds:Number($('#rotation').value)||7,is_active:$('#active').checked,display_mode:modeValue(),layout_width:layoutWidthValue(),hero_height_pct:Number($('#heroHeightPct').value)||100,mobile_swipe:$('#mobileSwipe').checked,starts_at:$('#campaignStarts').value||'',ends_at:$('#campaignEnds').value||''};
    }
    function saveDraft() {
      collectForm();
      try { localStorage.setItem(draftKey(),JSON.stringify({saved_at:Date.now(),state})); message('Borrador guardado en este navegador. Todavía no está publicado.'); return true; }
      catch { message('No se pudo guardar el borrador local. Publica los cambios para no perderlos.',true); return false; }
    }
    function markDirty() {
      isDirty=true; clearTimeout(draftTimer); draftTimer=setTimeout(saveDraft,700); renderPreview();
    }
    function restorePublished() {
      if(!lastPublished)return;
      Object.keys(state).forEach(key=>delete state[key]); Object.assign(state,JSON.parse(JSON.stringify(lastPublished)));
      isDirty=false; localStorage.removeItem(draftKey()); fillForm();
    }
    function restoreDraft(draft) {
      Object.keys(state).forEach(key=>delete state[key]); Object.assign(state,JSON.parse(JSON.stringify(draft)));
      isDirty=true; fillForm();
    }
    function payloadForSave() {
      collectForm();
      return {zone:activeZone,campaign:state.campaign,slides:state.slides,chips:zoneConfig().isPrimary?state.chips:[]};
    }
    async function fetchZoneState(zone) {
      const result=await api(`admin-store-campaigns?zone=${encodeURIComponent(zone)}`); const c=result.campaign;
      const savedMode=c?.display_mode;
      const zc=ZONE_CONFIG[zone]||ZONE_CONFIG['store-secondary'];
      const campaign={name:c?.name||zc.defaultName,rotation_seconds:Number(c?.rotation_seconds||7),is_active:c?String(c.is_active)==='1':true,display_mode:zc.restrictCarouselSingle&&['carousel','single'].includes(savedMode)?'split':(savedMode||zc.defaultMode),layout_width:['contained','full'].includes(c?.layout_width)?c.layout_width:(zc.defaultLayoutWidth||'contained'),hero_height_pct:Math.max(60,Math.min(100,Number(c?.hero_height_pct)||100)),mobile_swipe:String(c?.mobile_swipe)==='1',starts_at:c?.starts_at||'',ends_at:c?.ends_at||''};
      const slides=(result.slides||[]).map(slide=>({...slide,_editorKey:String(slide.id),card_layout:slide.card_layout==='side'?'side':'full',copy_background_color:/^#[0-9a-fA-F]{6}$/.test(slide.copy_background_color||'')?slide.copy_background_color:'#ffffff',image_width_pct:Number(slide.image_width_pct??55),is_active:String(slide.is_active)==='1',button_visible:String(slide.button_visible)!=='0',button2_visible:String(slide.button2_visible)==='1',images:Array.isArray(slide.images)?slide.images.map(image=>({...image,focal_x:Number(image.focal_x??50),focal_y:Number(image.focal_y??50),zoom_pct:Number(image.zoom_pct??100),is_primary:String(image.is_primary)==='1'||image.is_primary===true})):[]}));
      const chips=(result.chips||[]).map(chip=>({...chip,is_active:String(chip.is_active)==='1'}));
      return {campaign,slides,chips};
    }
    // Dos pestañas pueden editar la misma zona a la vez: antes de publicar, se vuelve a
    // consultar el servidor y se compara contra lo que esta pestaña sabía la última vez que
    // sincronizó (lastPublished). Si alguien más publicó cambios mientras tanto, se bloquea
    // el publish en lugar de sobrescribirlos en silencio (bug reportado: una pestaña vieja
    // borraba visualmente lo que otra acababa de publicar).
    async function checkPublishConflict() {
      if(!lastPublished) return false;
      try {
        const fresh=await fetchZoneState(activeZone);
        const freshKey=JSON.stringify({campaign:fresh.campaign,slides:fresh.slides,chips:fresh.chips});
        const lastKey=JSON.stringify({campaign:lastPublished.campaign,slides:lastPublished.slides,chips:lastPublished.chips});
        return freshKey!==lastKey;
      } catch { return false; }
    }
    async function publish() {
      const buttons=[$('#save'),$('#saveTop')]; buttons.forEach(button=>button.disabled=true);
      try {
        if(await checkPublishConflict()){
          message('Alguien más publicó cambios en esta zona desde otra pestaña/sesión mientras editabas. Recarga la página para ver lo último antes de publicar, o tus cambios sobrescribirían los suyos.',true);
          return;
        }
        const payload=payloadForSave();
        await api(`admin-store-campaigns?zone=${encodeURIComponent(activeZone)}`,{method:'POST',body:JSON.stringify(payload)});
        lastPublished=await fetchZoneState(activeZone); isDirty=false; localStorage.removeItem(draftKey()); message('Publicado. Los cambios de esta zona ya están guardados.');
      } catch(error) {
        message(error.message,true);
        const slideMatch=/^Banner #(\d+)/.exec(error.message||'');
        if(slideMatch){
          const card=$('#slides').querySelector(`[data-index="${Number(slideMatch[1])-1}"]`);
          if(card){card.querySelector('details').open=true;card.scrollIntoView({behavior:'smooth',block:'center'});}
        }
      }
      finally { buttons.forEach(button=>button.disabled=false); }
    }
    async function load() {
      try {
        const fresh=await fetchZoneState(activeZone);
        Object.assign(state.campaign,fresh.campaign);
        state.slides=fresh.slides;
        state.chips=fresh.chips;
        const zc=zoneConfig();
        lastPublished=JSON.parse(JSON.stringify(fresh));
        fillForm();
        const draft=JSON.parse(localStorage.getItem(draftKey())||'null');
        if(draft?.state&&draft.saved_at>Date.now()-7*24*60*60*1000&&await confirmDialog('Hay un borrador local de esta zona. ¿Quieres recuperarlo?',{okText:'Recuperar borrador',cancelText:'Descartar'})) restoreDraft(draft.state);
        else if(draft) localStorage.removeItem(draftKey());
        $('#zoneTitle').textContent=zc.title;
        $('#campaignName').closest('label').firstChild.textContent=zc.nameLabel;
        $('#chips').closest('.panel').classList.toggle('hidden',!zc.isPrimary);
        document.querySelectorAll('input[name="displayMode"]').forEach(input=>input.closest('.format-option').classList.toggle('hidden',zc.restrictCarouselSingle&&['carousel','single'].includes(input.value)));
        const supportsLayoutWidth=!['servicios-proyectos','servicios-especializados'].includes(activeZone);
        $('#layoutWidthOptions').classList.toggle('hidden',!supportsLayoutWidth);
        $('#layoutWidthOptions').previousElementSibling.classList.toggle('hidden',!supportsLayoutWidth);
        $('#layoutWidthOptions').nextElementSibling.classList.toggle('hidden',!supportsLayoutWidth);
        $('#campaignSlidesPanel .section-heading h3').textContent=zc.slidesHeading;
        $('#secondaryLayoutHelp').classList.toggle('hidden',!zc.restrictCarouselSingle);
        $('#mobileSwipeRow').classList.toggle('hidden',!zc.restrictCarouselSingle);
        $('#preview').setAttribute('aria-label',zc.previewLabel);
      } catch(error) { message(`No se pudo cargar el gestor de campañas: ${error.message}`,true); }
    }
    $('#loginForm').addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget;const data=Object.fromEntries(new FormData(form));try{const result=await api('login',{method:'POST',body:JSON.stringify(data)});if(!isAdmin(result.user)){showDenied();return;}if(result.token)localStorage.setItem('smartisp.adminToken',result.token);showApp(result.user);}catch(error){message(error.message,true,$('#loginMessage'));}});
    async function authenticate(){try{const result=await api('me');if(isAdmin(result.user))showApp(result.user);else showDenied();}catch(error){showLogin();if(error.status&&error.status!==401)message(error.message,true,$('#loginMessage'));}}
    document.querySelectorAll('input[name="displayMode"]').forEach(input=>input.addEventListener('change',()=>{collectForm();markDirty();}));
    document.querySelectorAll('input[name="layoutWidth"]').forEach(input=>input.addEventListener('change',()=>{collectForm();markDirty();}));
    document.querySelectorAll('input[name="campaignZone"]').forEach(input=>input.addEventListener('change',async()=>{
      if(input.value===activeZone)return;
      if(isDirty&&!await confirmDialog('Hay cambios sin publicar en esta zona. Se guardará un borrador local antes de cambiar. ¿Continuar?')){
        document.querySelector(`input[name="campaignZone"][value="${activeZone}"]`).checked=true;return;
      }
      if(isDirty)saveDraft();
      activeZone=input.value;isDirty=false;clearTimeout(draftTimer);
      const zc=zoneConfig();
      state.campaign={name:zc.defaultName,rotation_seconds:7,is_active:true,display_mode:zc.defaultMode,layout_width:zc.defaultLayoutWidth||'contained',hero_height_pct:100,starts_at:'',ends_at:''};
      state.slides=[];state.chips=[];
      await load();
    }));
    $('#refreshPreview').addEventListener('click',renderPreview);
    $('#preview').addEventListener('click',event=>{const control=event.target.closest('[data-preview-step]');if(!control)return;const active=state.slides.filter(slide=>slide.is_active!==false);previewSlideIndex=(previewSlideIndex+Number(control.dataset.previewStep)+active.length)%active.length;renderPreview();});
    $('#app').addEventListener('input',event=>{
      if(event.target.closest('.campaign-settings')) {
        if(event.target.id==='heroHeightPct') event.target.nextElementSibling.value=`${event.target.value}%`;
        collectForm(); markDirty(); renderPreview();
      }
    });
    $('#app').addEventListener('change',event=>{
      if(event.target.closest('.campaign-settings')) { collectForm(); markDirty(); }
    });
    function cropFrameRect(x,y,zoomPct){
      const size=Math.max(100/1.8,Math.min(100,10000/Math.max(100,Number(zoomPct)||100)));
      const left=Math.max(0,Math.min(100-size,Number(x)-size/2));
      const top=Math.max(0,Math.min(100-size,Number(y)-size/2));
      return {left,top,size};
    }
    function updateCropVisuals(imageCard,image){
      const x=Number(image.focal_x??50),y=Number(image.focal_y??50),zoom=Number(image.zoom_pct??100);
      imageCard.querySelectorAll('img').forEach(img=>{img.style.objectPosition=`${x}% ${y}%`;img.style.transform=`scale(${zoom/100})`;img.style.transformOrigin=`${x}% ${y}%`;});
      const crosshair=imageCard.querySelector('[data-crop-crosshair]');
      if(crosshair){crosshair.style.left=`${x}%`;crosshair.style.top=`${y}%`;}
      const frame=imageCard.querySelector('[data-crop-frame]');
      if(frame){const f=cropFrameRect(x,y,zoom);frame.style.left=`${f.left}%`;frame.style.top=`${f.top}%`;frame.style.width=`${f.size}%`;frame.style.height=`${f.size}%`;}
      const xRange=imageCard.querySelector('[data-image-field="focal_x"]'),yRange=imageCard.querySelector('[data-image-field="focal_y"]'),zRange=imageCard.querySelector('[data-image-field="zoom_pct"]');
      if(xRange){xRange.value=x;xRange.nextElementSibling.value=`${x}%`;}
      if(yRange){yRange.value=y;yRange.nextElementSibling.value=`${y}%`;}
      if(zRange){zRange.value=zoom;zRange.nextElementSibling.value=`${zoom}%`;}
    }
    let cropDrag=null;
    let cropResize=null;
    const cropPointFromEvent=(canvas,event)=>{
      const rect=canvas.getBoundingClientRect();
      const px=event.touches?event.touches[0].clientX:event.clientX, py=event.touches?event.touches[0].clientY:event.clientY;
      return {x:Math.round(Math.max(0,Math.min(100,(px-rect.left)/rect.width*100))),y:Math.round(Math.max(0,Math.min(100,(py-rect.top)/rect.height*100)))};
    };
    $('#slides').addEventListener('pointerdown',event=>{
      const handle=event.target.closest('[data-crop-handle]');
      if(handle){
        const canvas=handle.closest('[data-crop-canvas]');
        const imageCard=canvas.closest('[data-image]'),slide=state.slides[Number(imageCard.closest('[data-index]').dataset.index)],image=slide.images[Number(imageCard.dataset.image)];
        cropResize={canvas,imageCard,image};
        canvas.setPointerCapture?.(event.pointerId);
        event.stopPropagation();
        return;
      }
      const canvas=event.target.closest('[data-crop-canvas]');
      if(!canvas)return;
      const imageCard=canvas.closest('[data-image]'),slide=state.slides[Number(imageCard.closest('[data-index]').dataset.index)],image=slide.images[Number(imageCard.dataset.image)];
      cropDrag={canvas,imageCard,image};
      canvas.setPointerCapture?.(event.pointerId);
      const point=cropPointFromEvent(canvas,event);
      image.focal_x=point.x;image.focal_y=point.y;
      updateCropVisuals(imageCard,image);markDirty();
    });
    $('#slides').addEventListener('pointermove',event=>{
      if(cropResize){
        if(event.buttons===0){cropResize=null;return;}
        const point=cropPointFromEvent(cropResize.canvas,event);
        const fx=Number(cropResize.image.focal_x??50),fy=Number(cropResize.image.focal_y??50);
        const halfSize=Math.max(Math.abs(point.x-fx),Math.abs(point.y-fy));
        const size=Math.max(100/1.8,Math.min(100,halfSize*2));
        cropResize.image.zoom_pct=Math.round(Math.max(100,Math.min(180,10000/size)));
        updateCropVisuals(cropResize.imageCard,cropResize.image);
        return;
      }
      if(!cropDrag||event.buttons===0){if(cropDrag&&event.buttons===0)cropDrag=null;return;}
      const point=cropPointFromEvent(cropDrag.canvas,event);
      cropDrag.image.focal_x=point.x;cropDrag.image.focal_y=point.y;
      updateCropVisuals(cropDrag.imageCard,cropDrag.image);
    });
    ['pointerup','pointerleave','pointercancel'].forEach(evt=>$('#slides').addEventListener(evt,()=>{
      if(cropDrag){renderPreview();markDirty();cropDrag=null;}
      if(cropResize){renderPreview();markDirty();cropResize=null;}
    }));
    $('#slides').addEventListener('input',event=>{
      const imageCard=event.target.closest('[data-image]'),imageField=event.target.dataset.imageField;
      if(imageCard&&imageField){
        const slide=state.slides[Number(imageCard.closest('[data-index]').dataset.index)],image=slide.images[Number(imageCard.dataset.image)];
        image[imageField]=Number(event.target.value);
        event.target.nextElementSibling.value=`${event.target.value}%`;
        updateCropVisuals(imageCard,image);
        renderPreview();markDirty();return;
      }
      const card=event.target.closest('[data-index]'),field=event.target.dataset.field;if(!card||!field)return;
      const slide=state.slides[Number(card.dataset.index)]; slide[field]=event.target.type==='checkbox'?event.target.checked:event.target.value;
      if(event.target.type==='range')event.target.nextElementSibling.value=field==='image_transition_ms'?`${(Number(event.target.value)/1000).toFixed(2)}s`:`${event.target.value}%`;
      if(field==='show_trust_stats')card.querySelector('.trust-stats-fields').classList.toggle('hidden',!slide.show_trust_stats);
      const title=card.querySelector('.slide-summary-copy'); if(title)title.firstChild.textContent=slide.title||'Banner sin título';
      const hint=title?.querySelector('small'); if(hint)hint.textContent=`${slide.images.length} imagen${slide.images.length===1?'':'es'} · ${slide.is_active?'Visible en tienda':'Borrador oculto'}`;
      renderPreview();markDirty();
    });
    $('#slides').addEventListener('change',async event=>{
      const input=event.target;
      if(input.matches('[data-upload]')&&input.files?.length){
        const index=Number(input.closest('[data-index]').dataset.index),files=[...input.files]; input.disabled=true;
        try { for(const file of files){ if(file.size>8*1024*1024)throw new Error(`${file.name} supera el límite de 8 MB.`); const form=new FormData();form.append('image',file);const result=await api('upload-campaign-image',{method:'POST',body:form});const slide=state.slides[index];slide.images.push({image_url:result.url,image_alt:slide.title||file.name,focal_x:50,focal_y:50,zoom_pct:100,is_primary:slide.images.length===0}); } renderSlides();markDirty();message(`${files.length} imagen${files.length===1?'':'es'} añadida${files.length===1?'':'s'} a la galería.`); }
        catch(error){message(error.message,true);} finally{input.disabled=false;}
        return;
      }
      const card=input.closest('[data-index]'),field=input.dataset.field;if(card&&field){state.slides[Number(card.dataset.index)][field]=input.type==='checkbox'?input.checked:input.value;markDirty();}
    });
    $('#slides').addEventListener('click',event=>{
      if(event.target.closest('[data-crop-canvas]'))return;
      const button=event.target.closest('button'),card=button?.closest('[data-index]');if(!button||!card)return;
      const index=Number(card.dataset.index),images=state.slides[index].images;
      if(button.closest('summary'))event.preventDefault();
      if(button.hasAttribute('data-remove'))state.slides.splice(index,1);
      else if(button.hasAttribute('data-move')){const to=index+Number(button.dataset.move);if(to<0||to>=state.slides.length)return;[state.slides[index],state.slides[to]]=[state.slides[to],state.slides[index]];}
      else if(button.hasAttribute('data-clear-color')){state.slides[index][button.dataset.clearColor]='';}
      else if(button.hasAttribute('data-remove-image'))images.splice(Number(button.closest('[data-image]').dataset.image),1);
      else if(button.hasAttribute('data-primary-image')){images.forEach((image,i)=>image.is_primary=i===Number(button.closest('[data-image]').dataset.image));}
      else if(button.hasAttribute('data-image-up')||button.hasAttribute('data-image-down')){const imageIndex=Number(button.closest('[data-image]').dataset.image),to=imageIndex+(button.hasAttribute('data-image-up')?-1:1);if(to<0||to>=images.length)return;[images[imageIndex],images[to]]=[images[to],images[imageIndex]];images.forEach((image,i)=>image.sort_order=i);}
      else return;
      state.slides.forEach(slide=>{const primary=slide.images.find(image=>image.is_primary)||slide.images[0];slide.images.forEach(image=>image.is_primary=image===primary);slide.image_url=primary?.image_url||'';});
      renderSlides();markDirty();
    });
    $('#addSlide').addEventListener('click',()=>{if(state.slides.length>=12)return message('La campaña admite hasta 12 banners.',true);preferredExpandedKey=`local-${++slideKeyCounter}`;state.slides.push({_editorKey:preferredExpandedKey,eyebrow:'',eyebrow_line2:'',title:'',title_line2:'',subtitle:'',button_text:'Explorar',button_visible:true,button2_text:'',button2_url:'',button2_visible:false,target_url:'/tienda.html#catalogo',image_url:'',image_alt:'',card_layout:'full',copy_background_color:'#ffffff',bullet_style:'none',bullet_color:'#16a34a',title_color:'',title_line2_color:'',eyebrow_color:'',subtitle_color:'',eyebrow_size:'md',title_size:'md',subtitle_size:'md',image_fit:'cover',image_width_pct:55,image_opacity:100,overlay_opacity:68,image_interval_seconds:5,image_transition_ms:1000,badge_label:'',badge_tone:'discount',show_trust_stats:false,trust_stats_clients_override:'',trust_stats_projects_override:'',trust_stats_support_override:'',images:[],is_active:true});renderSlides();markDirty();});
    $('#chips').addEventListener('input',event=>{const row=event.target.closest('[data-chip]'),field=event.target.dataset.field;if(row&&field){state.chips[Number(row.dataset.chip)][field]=event.target.type==='checkbox'?event.target.checked:event.target.value;markDirty();}});
    $('#chips').addEventListener('change',event=>{const row=event.target.closest('[data-chip]'),field=event.target.dataset.field;if(row&&field){state.chips[Number(row.dataset.chip)][field]=event.target.type==='checkbox'?event.target.checked:event.target.value;markDirty();}});
    $('#chips').addEventListener('click',event=>{const button=event.target.closest('[data-remove-chip]');if(!button)return;state.chips.splice(Number(button.closest('[data-chip]').dataset.chip),1);renderChips();markDirty();});
    $('#addChip').addEventListener('click',()=>{if(state.chips.length>=8)return message('Puedes publicar hasta 8 accesos.',true);state.chips.push({label:'',icon:'tag',target_url:'/tienda.html#catalogo',is_active:true});renderChips();markDirty();});
    document.querySelectorAll('#save,#saveTop').forEach(button=>button.addEventListener('click',publish));
    $('#saveDraft').addEventListener('click',saveDraft);
    $('#cancelEditor').addEventListener('click',async()=>{if(isDirty&&!await confirmDialog('¿Descartar los cambios no publicados y volver a la última versión guardada?',{okText:'Descartar cambios',cancelText:'Seguir editando',danger:true}))return;restorePublished();message('Cambios descartados.');});
    document.addEventListener('click',event=>{
      const link=event.target.closest('a[href]');
      if(!link||!isDirty)return;
      event.preventDefault();
      saveDraft();
      confirmDialog('Hay cambios no publicados. Se guardó un borrador en este navegador. ¿Quieres salir de esta pantalla?',{okText:'Salir',cancelText:'Quedarme'}).then(ok=>{
        if(ok){isDirty=false;window.open(link.href,link.target||'_self');}
      });
    });
    window.addEventListener('beforeunload',event=>{if(isDirty){event.preventDefault();event.returnValue='';}});
    window.lucide?.createIcons();authenticate();
  })();
