		lucide.createIcons();

		// Prevent link navigation in editor mode
		document.addEventListener('click', e => {
			const link = e.target.closest('a');
			if (!link) return;
			if (link.closest('#smartisp-admin-nav')) return;
			if (link.closest('#editorTopBar') || link.closest('.editor-modal-overlay') || link.closest('.card-action-floating-bar')) return;
			if (!e.ctrlKey && !e.metaKey) {
				e.preventDefault();
			}
		});

		let isDirty = false;
		let selectedCardBox = null;
		let activeTextElement = null;

		const landingState = {
			logo_type: 'text',
			logo_image: '',
			logo_height: '38',
			logo_dark_image: '',
			logo_dark_height: '42',
			logo_dark_invert: false,
			logo_text: 'smartisp',
			logo_badge: 'Infraestructura TI'
		};

		const showToast = (msg, isError = false) => {
			const toast = document.querySelector('#editorToast');
			const msgEl = document.querySelector('#toastMsg');
			const iconEl = document.querySelector('#toastIcon');
			if (!toast || !msgEl) return;
			msgEl.textContent = msg;
			if (iconEl) iconEl.textContent = isError ? 'Error' : 'Listo';
			toast.classList.toggle('error', isError);
			toast.classList.add('show');
			setTimeout(() => { toast.classList.remove('show'); }, 3500);
		};

		const markDirty = (dirty = true) => {
			isDirty = dirty;
			const dot = document.querySelector('#statusDot');
			const text = document.querySelector('#statusMessageText');
			const saveBtn = document.querySelector('#saveLandingChangesBtn');
			if (dot) dot.classList.toggle('dirty', dirty);
			if (text) text.textContent = dirty ? 'Hay cambios sin guardar' : 'Todos los cambios guardados';
			if (saveBtn) saveBtn.classList.toggle('has-changes', dirty);
		};

		window.addEventListener('beforeunload', e => {
			if (isDirty) {
				e.preventDefault();
				e.returnValue = 'Tienes cambios sin guardar.';
			}
		});

		// Listen to all contenteditable edits
		const bindEditableEvents = () => {
			document.querySelectorAll('[data-landing-key]').forEach(el => {
				if (el.tagName !== 'BUTTON' && !el.closest('.card-action-floating-bar') && !el.closest('.add-card-slot')) {
					el.contentEditable = 'true';
					el.spellcheck = false;
				}
				if (el._bound) return;
				el._bound = true;
				el.addEventListener('input', () => { markDirty(true); });
				el.addEventListener('focus', () => {
					activeTextElement = el;
					positionFormatBar(el);
				});
			});
		};
		bindEditableEvents();

		// =========================================================================
		// FLOATING TEXT FORMATTING TOOLBAR & SIZE GRID
		// =========================================================================
		const formatBar = document.querySelector('#floatingFormatBar');
		const sizePopover = document.querySelector('#sizeGridPopover');
		const colorPopover = document.querySelector('#colorGridPopover');
		const currentSizeLabel = document.querySelector('#currentSizeLabel');
		const sizeSlider = document.querySelector('#sizeRangeSlider');
		const sliderVal = document.querySelector('#sliderValueText');

		const positionFormatBar = el => {
			if (!el) return;
			const rect = el.getBoundingClientRect();
			const top = Math.max(65, rect.top - 48);
			const left = Math.min(window.innerWidth - 380, Math.max(10, rect.left));
			formatBar.style.top = top + 'px';
			formatBar.style.left = left + 'px';
			formatBar.classList.add('visible');

			// Read current computed font size
			const currentSize = parseInt(window.getComputedStyle(el).fontSize, 10) || 16;
			if (currentSizeLabel) currentSizeLabel.textContent = currentSize + 'px';
			if (sizeSlider) sizeSlider.value = currentSize;
			if (sliderVal) sliderVal.textContent = currentSize + 'px';
		};

		document.addEventListener('click', e => {
			if (e.target.closest('#floatingFormatBar') || e.target.closest('[data-landing-key]')) return;
			formatBar?.classList.remove('visible');
			sizePopover?.classList.remove('open');
			colorPopover?.classList.remove('open');
			activeTextElement = null;
		});

		// Toggle Popovers
		document.querySelector('#formatSizeToggleBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			sizePopover.classList.toggle('open');
			colorPopover.classList.remove('open');
		});

		document.querySelector('#formatColorToggleBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			colorPopover.classList.toggle('open');
			sizePopover.classList.remove('open');
		});

		// Apply Font Size
		const setElementFontSize = sizePx => {
			if (!activeTextElement) return;
			activeTextElement.style.fontSize = sizePx;
			if (currentSizeLabel) currentSizeLabel.textContent = sizePx;
			if (sliderVal) sliderVal.textContent = sizePx;
			if (sizeSlider) sizeSlider.value = parseInt(sizePx, 10);
			markDirty(true);
		};

		document.querySelectorAll('.size-chip').forEach(chip => {
			chip.addEventListener('click', e => {
				e.stopPropagation();
				setElementFontSize(chip.dataset.size);
				sizePopover.classList.remove('open');
			});
		});

		sizeSlider?.addEventListener('input', e => {
			setElementFontSize(e.target.value + 'px');
		});

		// Step A- / A+
		document.querySelector('#stepDownSizeBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (!activeTextElement) return;
			const cur = parseInt(window.getComputedStyle(activeTextElement).fontSize, 10) || 16;
			const next = Math.max(10, cur - 2);
			setElementFontSize(next + 'px');
		});

		document.querySelector('#stepUpSizeBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (!activeTextElement) return;
			const cur = parseInt(window.getComputedStyle(activeTextElement).fontSize, 10) || 16;
			const next = Math.min(72, cur + 2);
			setElementFontSize(next + 'px');
		});

		// Color Swatches
		document.querySelectorAll('.color-swatch-btn').forEach(btn => {
			btn.addEventListener('click', e => {
				e.stopPropagation();
				if (!activeTextElement) return;
				activeTextElement.style.color = btn.dataset.color;
				colorPopover.classList.remove('open');
				markDirty(true);
			});
		});

		// Bold, Italic, Underline
		document.querySelector('#boldToggleBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (!activeTextElement) return;
			const cur = window.getComputedStyle(activeTextElement).fontWeight;
			activeTextElement.style.fontWeight = (cur === '700' || cur === 'bold' || cur === '800') ? 'normal' : 'bold';
			markDirty(true);
		});

		document.querySelector('#italicToggleBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (!activeTextElement) return;
			const cur = window.getComputedStyle(activeTextElement).fontStyle;
			activeTextElement.style.fontStyle = (cur === 'italic') ? 'normal' : 'italic';
			markDirty(true);
		});

		document.querySelector('#underlineToggleBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (!activeTextElement) return;
			const cur = activeTextElement.style.textDecoration;
			activeTextElement.style.textDecoration = (cur === 'underline') ? 'none' : 'underline';
			markDirty(true);
		});

		// Alignments
		document.querySelector('#alignLeftBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (activeTextElement) { activeTextElement.style.textAlign = 'left'; markDirty(true); }
		});
		document.querySelector('#alignCenterBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (activeTextElement) { activeTextElement.style.textAlign = 'center'; markDirty(true); }
		});
		document.querySelector('#alignRightBtn')?.addEventListener('click', e => {
			e.stopPropagation();
			if (activeTextElement) { activeTextElement.style.textAlign = 'right'; markDirty(true); }
		});

		// =========================================================================
		// SELECTABLE & DELETABLE BOXES (SELECCIÓN Y SUPR/DELETE)
		// =========================================================================
		const setSelectedCardBox = box => {
			if (selectedCardBox && selectedCardBox !== box) {
				selectedCardBox.classList.remove('is-card-selected');
			}
			selectedCardBox = box;
			if (selectedCardBox) {
				selectedCardBox.classList.add('is-card-selected');
			}
		};

		const updateAdvGridCols = () => {
			const grid = document.querySelector('.advantages-grid');
			if (!grid) return;
			const cards = grid.querySelectorAll('.advantage-card');
			if (cards.length > 0 && window.innerWidth >= 992) {
				grid.style.setProperty('--adv-cols', cards.length);
			} else {
				grid.style.removeProperty('--adv-cols');
			}
		};

		const deleteBox = box => {
			if (!box) return;
			const isAdv = box.classList.contains('advantage-card');
			box.style.transform = 'scale(0.85)';
			box.style.opacity = '0';
			setTimeout(() => {
				box.remove();
				if (selectedCardBox === box) selectedCardBox = null;
				if (isAdv) updateAdvGridCols();
				markDirty(true);
				showToast('Cuadro eliminado');
			}, 180);
		};

		const duplicateBox = box => {
			if (!box) return;
			const isAdv = box.classList.contains('advantage-card');
			const clone = box.cloneNode(true);
			clone.classList.remove('is-card-selected');
			// Remover botones de edición clonados antes de re-vincular
			clone.querySelectorAll('.btn-add-feature, .btn-delete-feature, .card-action-floating-bar').forEach(el => el.remove());
			// Generate unique keys for clone
			const rand = Math.random().toString(36).slice(2, 7);
			clone.querySelectorAll('[data-landing-key]').forEach(el => {
				el.dataset.landingKey = el.dataset.landingKey + '_dup_' + rand;
			});
			box.after(clone);
			bindBoxEvents(clone);
			if (clone.classList.contains('solution-card')) {
				bindSolutionCardFeatures(clone);
			}
			bindEditableEvents();
			lucide.createIcons();
			setSelectedCardBox(clone);
			if (isAdv) updateAdvGridCols();
			markDirty(true);
			showToast('Cuadro duplicado');
		};

		const floatingBarHtml = `<div class="card-action-floating-bar"><button type="button" class="card-action-btn duplicate-btn" title="Duplicar este cuadro">Duplicar</button><button type="button" class="card-action-btn delete-btn" title="Eliminar cuadro (o presiona Supr en el teclado)">Eliminar (Supr)</button></div>`;

		const attachFloatingBarIfNeeded = box => {
			if (!box) return;
			if (!box.querySelector('.card-action-floating-bar')) {
				box.insertAdjacentHTML('afterbegin', floatingBarHtml);
			}
			box.classList.add('selectable-box');
			box.dataset.deletableBox = 'true';
		};

		const bindBoxEvents = box => {
			if (!box) return;
			attachFloatingBarIfNeeded(box);
			if (box._boxBound) return;
			box._boxBound = true;

			box.addEventListener('click', e => {
				// Don't select box if user is actively clicking to edit text inside
				if (e.target.closest('[data-landing-key]') || e.target.isContentEditable || e.target.closest('[contenteditable="true"]')) {
					return;
				}
				// Don't select box if user clicked on icon or floating actions
				if (e.target.closest('i, svg.lucide, .solution-icon-wrap, .advantage-icon, .card-action-floating-bar')) {
					return;
				}
				e.stopPropagation();
				setSelectedCardBox(box);
			});

			// Icon click to change
			box.querySelectorAll('i, svg.lucide, .solution-icon-wrap, .advantage-icon, .mv-card-icon, .contact-icon').forEach(iconEl => {
				iconEl.setAttribute('title', 'Haz clic para cambiar el icono');
				iconEl.addEventListener('click', e => {
					e.stopPropagation();
					e.preventDefault();
					const targetWrap = iconEl.closest('.advantage-icon, .solution-icon-wrap, .mv-card-icon, .contact-icon') || iconEl;
					openIconPicker(targetWrap, iconEl);
				});
			});

			const delBtn = box.querySelector('.delete-btn');
			delBtn?.addEventListener('click', e => {
				e.stopPropagation();
				deleteBox(box);
			});

			const dupBtn = box.querySelector('.duplicate-btn');
			dupBtn?.addEventListener('click', e => {
				e.stopPropagation();
				duplicateBox(box);
			});
		};

		// ICON PICKER LOGIC
		const iconPicker = document.querySelector('#iconPickerPopover');
		const iconPickerGrid = document.querySelector('#iconPickerGrid');
		let activeIconTarget = null;

		const AVAILABLE_ICONS = [
			'award', 'zap', 'users', 'shield-alert', 'shield-check',
			'check-circle-2', 'cpu', 'clock', 'heart', 'star',
			'headphones', 'rocket', 'globe', 'truck', 'credit-card',
			'network', 'server', 'radio', 'laptop', 'activity',
			'lock', 'eye', 'target', 'map-pin', 'phone'
		];

		if (iconPickerGrid) {
			iconPickerGrid.innerHTML = AVAILABLE_ICONS.map(name => `
				<button type="button" class="icon-picker-item" data-icon-name="${name}" title="${name}">
					<i data-lucide="${name}" width="20"></i>
				</button>
			`).join('');
			if (window.lucide) lucide.createIcons();

			iconPickerGrid.addEventListener('click', e => {
				const item = e.target.closest('.icon-picker-item');
				if (!item || !activeIconTarget) return;
				const iconName = item.dataset.iconName;
				const isWrap = activeIconTarget.classList.contains('advantage-icon') || activeIconTarget.classList.contains('solution-icon-wrap') || activeIconTarget.classList.contains('mv-card-icon') || activeIconTarget.classList.contains('contact-icon');
				const curIcon = isWrap ? (activeIconTarget.querySelector('i, svg.lucide') || activeIconTarget) : activeIconTarget;
				const width = curIcon.getAttribute('width') || '28';
				
				const newI = document.createElement('i');
				newI.dataset.lucide = iconName;
				newI.setAttribute('width', width);
				
				if (isWrap) {
					activeIconTarget.innerHTML = '';
					activeIconTarget.appendChild(newI);
				} else {
					curIcon.replaceWith(newI);
				}
				lucide.createIcons();
				
				const createdSvg = (isWrap ? activeIconTarget.querySelector('svg.lucide') : null) || newI;
				createdSvg.setAttribute('title', 'Haz clic para cambiar el icono');
				createdSvg.addEventListener('click', ev => {
					ev.stopPropagation();
					ev.preventDefault();
					openIconPicker(isWrap ? activeIconTarget : createdSvg, createdSvg);
				});

				markDirty(true);
				showToast(`Icono cambiado a "${iconName}"`);
				iconPicker.classList.remove('open');
				activeIconTarget = null;
			});
		}

		document.querySelector('#closeIconPickerBtn')?.addEventListener('click', () => {
			iconPicker?.classList.remove('open');
			activeIconTarget = null;
		});

		const openIconPicker = (targetContainer, clickedEl) => {
			if (!iconPicker) return;
			activeIconTarget = targetContainer;
			const rect = clickedEl.getBoundingClientRect();
			const top = Math.min(window.innerHeight - 280, Math.max(70, rect.bottom + 8));
			const left = Math.min(window.innerWidth - 310, Math.max(10, rect.left - 20));
			iconPicker.style.top = top + 'px';
			iconPicker.style.left = left + 'px';
			iconPicker.classList.add('open');
		};

		document.querySelectorAll('.selectable-box').forEach(bindBoxEvents);

		// Global click outside to deselect
		document.addEventListener('click', e => {
			if (!e.target.closest('#iconPickerPopover') && !e.target.closest('i, svg.lucide, .solution-icon-wrap, .advantage-icon')) {
				iconPicker?.classList.remove('open');
			}
			if (!e.target.closest('.selectable-box') && !e.target.closest('#editorTopBar') && !e.target.closest('#floatingFormatBar') && !e.target.closest('#iconPickerPopover')) {
				setSelectedCardBox(null);
			}
		});

		// SUPRIMIR / DELETE KEY EVENT
		document.addEventListener('keydown', e => {
			if ((e.key === 'Delete' || e.key === 'Backspace') && selectedCardBox) {
				// If user is currently typing in an editable text or input, don't delete card
				const active = document.activeElement;
				if (active && (active.isContentEditable || active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) {
					return;
				}
				e.preventDefault();
				deleteBox(selectedCardBox);
			}
		});

		// =========================================================================
		// ADD NEW CARDS ("AGREGAR CUADRO")
		// =========================================================================

		const handleAddSolutionCard = () => {
			const id = Date.now().toString().slice(-4);
			const card = document.createElement('div');
			card.className = 'solution-card selectable-box';
			card.dataset.deletableBox = 'true';
			card.innerHTML = `${floatingBarHtml}
				<div>
					<div class="solution-icon-wrap"><i data-lucide="network" width="26"></i></div>
					<h3 data-landing-key="custom_sol_${id}_h3" contenteditable="true" spellcheck="false">Nueva Línea de Solución</h3>
					<p data-landing-key="custom_sol_${id}_p" contenteditable="true" spellcheck="false">Describe aquí los detalles y beneficios de esta solución de conectividad.</p>
					<ul>
						<li><i data-lucide="check-circle-2" width="14"></i> <span data-landing-key="custom_sol_${id}_li1" contenteditable="true" spellcheck="false">Característica destacada 1</span></li>
						<li><i data-lucide="check-circle-2" width="14"></i> <span data-landing-key="custom_sol_${id}_li2" contenteditable="true" spellcheck="false">Característica destacada 2</span></li>
						<li><i data-lucide="check-circle-2" width="14"></i> <span data-landing-key="custom_sol_${id}_li3" contenteditable="true" spellcheck="false">Característica destacada 3</span></li>
					</ul>
				</div>
				<a href="tienda.html" class="btn-solution-link">
					<span data-landing-key="custom_sol_${id}_btn" contenteditable="true" spellcheck="false">Ver en la Tienda</span>
					<i data-lucide="arrow-up-right" width="14"></i>
				</a>`;
			const slot = document.querySelector('.solutions-grid .add-card-slot');
			if (slot) slot.before(card);
			else document.querySelector('.solutions-grid')?.appendChild(card);
			bindBoxEvents(card);
			bindSolutionCardFeatures(card);
			bindEditableEvents();
			lucide.createIcons();
			setSelectedCardBox(card);
			card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			markDirty(true);
			showToast('Nueva solución agregada. Haz clic en el texto para editar.');
		};

		const handleAddAdvantageCard = () => {
			const id = Date.now().toString().slice(-4);
			const card = document.createElement('div');
			card.className = 'advantage-card selectable-box';
			card.dataset.deletableBox = 'true';
			card.innerHTML = `${floatingBarHtml}
				<div class="advantage-icon"><i data-lucide="shield-check" width="28"></i></div>
				<h4 data-landing-key="custom_adv_${id}_h4" contenteditable="true" spellcheck="false">Nueva Ventaja Competitiva</h4>
				<p data-landing-key="custom_adv_${id}_p" contenteditable="true" spellcheck="false">Describe aquí la razón por la que tus clientes deben elegir tu empresa.</p>`;
			const slot = document.querySelector('.advantages-grid .add-card-slot');
			if (slot) slot.before(card);
			else document.querySelector('.advantages-grid')?.appendChild(card);
			bindBoxEvents(card);
			bindEditableEvents();
			lucide.createIcons();
			setSelectedCardBox(card);
			updateAdvGridCols();
			card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			markDirty(true);
			showToast('Nueva ventaja agregada.');
		};

		const handleAddValueBox = () => {
			const id = Date.now().toString().slice(-4);
			const card = document.createElement('div');
			card.className = 'value-box selectable-box';
			card.dataset.deletableBox = 'true';
			card.innerHTML = `${floatingBarHtml}
				<i data-lucide="award" width="28"></i>
				<h5 data-landing-key="custom_val_${id}_h5" contenteditable="true" spellcheck="false">Nuevo Pilar Corporativo</h5>
				<p data-landing-key="custom_val_${id}_p" contenteditable="true" spellcheck="false">Describe el compromiso, garantía o diferencial técnico de la empresa.</p>`;
			const slot = document.querySelector('.about-visual .add-card-slot');
			if (slot) slot.before(card);
			else document.querySelector('.about-visual')?.appendChild(card);
			bindBoxEvents(card);
			bindEditableEvents();
			lucide.createIcons();
			setSelectedCardBox(card);
			card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			markDirty(true);
			showToast('Nuevo pilar agregado.');
		};

		const handleAddStatItem = () => {
			const id = Date.now().toString().slice(-4);
			const card = document.createElement('div');
			card.className = 'stat-item selectable-box';
			card.dataset.deletableBox = 'true';
			card.innerHTML = `${floatingBarHtml}
				<span class="stat-number" data-landing-key="custom_stat_${id}_num" contenteditable="true" spellcheck="false">+100</span>
				<span class="stat-label" data-landing-key="custom_stat_${id}_label" contenteditable="true" spellcheck="false">Título de Estadística</span>
				<span class="stat-desc" data-landing-key="custom_stat_${id}_desc" contenteditable="true" spellcheck="false">Descripción de este logro o métrica.</span>`;
			const slot = document.querySelector('.stats-grid .add-card-slot');
			if (slot) slot.before(card);
			else document.querySelector('.stats-grid')?.appendChild(card);
			bindBoxEvents(card);
			bindEditableEvents();
			lucide.createIcons();
			setSelectedCardBox(card);
			card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			markDirty(true);
			showToast('Nueva estadística agregada.');
		};

		// Event delegation for all add-grid-item buttons (robust against innerHTML recreation)
		document.addEventListener('click', e => {
			const addBtn = e.target.closest('.btn-add-grid-item');
			if (!addBtn) return;
			e.preventDefault();
			e.stopPropagation();

			if (addBtn.id === 'addValueBoxBtn' || addBtn.closest('.about-visual')) {
				handleAddValueBox();
			} else if (addBtn.id === 'addSolutionCardBtn' || addBtn.closest('.solutions-grid')) {
				handleAddSolutionCard();
			} else if (addBtn.id === 'addAdvantageCardBtn' || addBtn.closest('.advantages-grid')) {
				handleAddAdvantageCard();
			} else if (addBtn.id === 'addStatItemBtn' || addBtn.closest('.stats-grid')) {
				handleAddStatItem();
			}
		});

		// =========================================================================
		// SOLUTION CARD DYNAMIC FEATURES (ASPECTOS CON VISTO)
		// =========================================================================
		const bindSolutionCardFeatures = card => {
			if (!card) return;
			const ul = card.querySelector('ul');
			if (!ul) return;

			// 1. Armar cada li existente con botón de eliminar
			ul.querySelectorAll('li').forEach(li => {
				const textSpan = li.querySelector('span') || li;
				if (!textSpan.hasAttribute('contenteditable')) {
					textSpan.contentEditable = 'true';
					textSpan.spellcheck = false;
				}

				if (!li.querySelector('.btn-delete-feature')) {
					const delBtn = document.createElement('button');
					delBtn.type = 'button';
					delBtn.className = 'btn-delete-feature';
					delBtn.title = 'Eliminar este aspecto';
					delBtn.innerHTML = '&times;';
					delBtn.addEventListener('click', e => {
						e.stopPropagation();
						li.style.transform = 'scale(0.85)';
						li.style.opacity = '0';
						setTimeout(() => {
							li.remove();
							markDirty(true);
							showToast('Aspecto eliminado');
						}, 150);
					});
					li.appendChild(delBtn);
				}
			});

			// 2. Insertar botón "Agregar aspecto" después de ul si no existe
			let addBtn = card.querySelector('.btn-add-feature');
			if (!addBtn) {
				addBtn = document.createElement('button');
				addBtn.type = 'button';
				addBtn.className = 'btn-add-feature';
				addBtn.innerHTML = `<i data-lucide="plus" width="13"></i> <span>Agregar aspecto</span>`;
				ul.after(addBtn);
			}

			if (!addBtn._bound) {
				addBtn._bound = true;
				addBtn.addEventListener('click', e => {
					e.stopPropagation();
					const id = Date.now().toString().slice(-5);
					const newLi = document.createElement('li');
					newLi.innerHTML = `<i data-lucide="check-circle-2" width="14"></i> <span data-landing-key="custom_feat_${id}" contenteditable="true" spellcheck="false">Nuevo aspecto destacado</span><button type="button" class="btn-delete-feature" title="Eliminar este aspecto">&times;</button>`;

					const delBtn = newLi.querySelector('.btn-delete-feature');
					delBtn.addEventListener('click', ev => {
						ev.stopPropagation();
						newLi.style.transform = 'scale(0.85)';
						newLi.style.opacity = '0';
						setTimeout(() => {
							newLi.remove();
							markDirty(true);
							showToast('Aspecto eliminado');
						}, 150);
					});

					ul.appendChild(newLi);
					bindEditableEvents();
					if (window.lucide && typeof lucide.createIcons === 'function') {
						lucide.createIcons();
					}

					const span = newLi.querySelector('span');
					if (span) {
						span.focus();
						const range = document.createRange();
						range.selectNodeContents(span);
						const sel = window.getSelection();
						sel.removeAllRanges();
						sel.addRange(range);
					}
					markDirty(true);
					showToast('Nuevo aspecto agregado. Escribe el texto.');
				});
			}
		};

		// =========================================================================
		// LOGO MODAL & RENDERING
		// =========================================================================
		const renderLandingLogo = () => {
			const navLogoWrap = document.querySelector('.navbar .landing-logo-container');
			const footLogoWrap = document.querySelector('.footer-brand .landing-footer-logo-container');

			const mode = landingState.logo_type || 'text';
			const lightImg = landingState.logo_image || '';
			const lightHeight = parseInt(landingState.logo_height, 10) || 38;
			const darkImg = landingState.logo_dark_image || lightImg;
			const darkHeight = parseInt(landingState.logo_dark_height, 10) || 42;
			const darkInvert = !!landingState.logo_dark_invert;
			const text = (landingState.logo_text || 'smartisp').trim();
			const badge = (landingState.logo_badge || '').trim();

			if (mode === 'image') {
				if (navLogoWrap) {
					if (lightImg) {
						navLogoWrap.innerHTML = `<img src="${lightImg}" alt="Logo" style="height:${lightHeight}px; max-height:140px; width:auto; object-fit:contain; vertical-align:middle; display:inline-block;">${badge ? `<span class="logo-badge" style="margin-left:8px;">${badge}</span>` : ''}`;
					} else {
						const mid = Math.ceil(text.length / 2);
						navLogoWrap.innerHTML = `${text.slice(0, mid)}<b style="color:var(--blue, #087ea4);">${text.slice(mid)}</b><span style="color:#f5a524;">.</span> ${badge ? `<span class="logo-badge" style="margin-left:8px;">${badge}</span>` : ''}`;
					}
				}
				if (footLogoWrap) {
					if (darkImg) {
						const filterStyle = darkInvert ? 'filter: brightness(0) invert(1);' : '';
						footLogoWrap.innerHTML = `<img src="${darkImg}" alt="Logo Footer" style="height:${darkHeight}px; max-height:140px; width:auto; object-fit:contain; vertical-align:middle; display:inline-block; ${filterStyle}">`;
					} else {
						const mid = Math.ceil(text.length / 2);
						footLogoWrap.innerHTML = `${text.slice(0, mid)}<b style="color:var(--blue, #087ea4);">${text.slice(mid)}</b><span style="color:#f5a524;">.</span>`;
					}
				}
			} else {
				const mid = Math.ceil(text.length / 2);
				const part1 = text.slice(0, mid);
				const part2 = text.slice(mid);
				const formatted = `${part1}<b style="color:var(--blue, #087ea4);">${part2}</b><span style="color:#f5a524;">.</span>`;
				if (navLogoWrap) {
					navLogoWrap.innerHTML = `${formatted} ${badge ? `<span class="logo-badge">${badge}</span>` : ''}`;
				}
				if (footLogoWrap) {
					footLogoWrap.innerHTML = formatted;
				}
			}
		};

		const switchLogoTab = tabName => {
			document.querySelector('#tabLogoLightBtn')?.classList.toggle('active', tabName === 'light');
			document.querySelector('#tabLogoDarkBtn')?.classList.toggle('active', tabName === 'dark');
			document.querySelector('#tabLogoTextBtn')?.classList.toggle('active', tabName === 'text');

			const lightP = document.querySelector('#modalLogoLightPanel');
			const darkP = document.querySelector('#modalLogoDarkPanel');
			const textP = document.querySelector('#modalLogoTextPanel');
			if (lightP) lightP.style.display = tabName === 'light' ? 'block' : 'none';
			if (darkP) darkP.style.display = tabName === 'dark' ? 'block' : 'none';
			if (textP) textP.style.display = tabName === 'text' ? 'block' : 'none';

			if (tabName === 'text') {
				landingState.logo_type = 'text';
			} else {
				landingState.logo_type = 'image';
			}
		};

		const openLogoModal = () => {
			const modal = document.querySelector('#landingLogoModal');
			if (!modal) return;
			const isImg = landingState.logo_type === 'image';
			switchLogoTab(isImg ? 'light' : 'text');

			// Light preview & values
			if (landingState.logo_image) {
				const pImg = document.querySelector('#modalLogoPreviewImg');
				if (pImg) {
					pImg.src = landingState.logo_image;
					pImg.style.height = (landingState.logo_height || 38) + 'px';
				}
				document.querySelector('#modalLogoPreviewWrap').style.display = 'block';
				document.querySelector('#modalLogoPrompt').style.display = 'none';
			} else {
				document.querySelector('#modalLogoPreviewWrap').style.display = 'none';
				document.querySelector('#modalLogoPrompt').style.display = 'block';
			}
			const lHeight = landingState.logo_height || '38';
			const lRange = document.querySelector('#modalLogoHeightRange');
			const lNum = document.querySelector('#modalLogoHeightNumber');
			const lVal = document.querySelector('#modalLogoHeightVal');
			if (lRange) lRange.value = lHeight;
			if (lNum) lNum.value = lHeight;
			if (lVal) lVal.textContent = lHeight + 'px';
			const lBadge = document.querySelector('#modalLogoBadgeInput');
			if (lBadge) lBadge.value = landingState.logo_badge || 'Infraestructura TI';

			// Dark preview & values
			const darkImg = landingState.logo_dark_image || '';
			if (darkImg) {
				const pDarkImg = document.querySelector('#modalLogoDarkPreviewImg');
				if (pDarkImg) {
					pDarkImg.src = darkImg;
					pDarkImg.style.height = (landingState.logo_dark_height || 42) + 'px';
					pDarkImg.style.filter = landingState.logo_dark_invert ? 'brightness(0) invert(1)' : 'none';
				}
				document.querySelector('#modalLogoDarkPreviewWrap').style.display = 'block';
				document.querySelector('#modalLogoDarkPrompt').style.display = 'none';
			} else {
				document.querySelector('#modalLogoDarkPreviewWrap').style.display = 'none';
				document.querySelector('#modalLogoDarkPrompt').style.display = 'block';
			}
			const dHeight = landingState.logo_dark_height || '42';
			const dRange = document.querySelector('#modalLogoDarkHeightRange');
			const dNum = document.querySelector('#modalLogoDarkHeightNumber');
			const dVal = document.querySelector('#modalLogoDarkHeightVal');
			if (dRange) dRange.value = dHeight;
			if (dNum) dNum.value = dHeight;
			if (dVal) dVal.textContent = dHeight + 'px';
			const dInvert = document.querySelector('#modalDarkAutoInvert');
			if (dInvert) dInvert.checked = !!landingState.logo_dark_invert;

			// Text mode
			const tInput = document.querySelector('#modalLogoTextInput');
			if (tInput) tInput.value = landingState.logo_text || 'smartisp';
			const tBadge = document.querySelector('#modalLogoBadgeInput2');
			if (tBadge) tBadge.value = landingState.logo_badge || 'Infraestructura TI';
			updateModalTextPreview();

			modal.classList.add('open');
		};

		const closeLogoModal = () => {
			document.querySelector('#landingLogoModal')?.classList.remove('open');
		};

		const updateModalTextPreview = () => {
			const text = (document.querySelector('#modalLogoTextInput')?.value || 'smartisp').trim();
			const box = document.querySelector('#modalTextPreviewBox');
			if (!box) return;
			const mid = Math.ceil(text.length / 2);
			const part1 = text.slice(0, mid);
			const part2 = text.slice(mid);
			box.innerHTML = `${part1}<b style="color:#087ea4;">${part2}</b><span style="color:#f5a524;">.</span>`;
		};

		document.querySelector('#openLogoModalBtn')?.addEventListener('click', openLogoModal);
		document.querySelector('#closeLogoModalBtn')?.addEventListener('click', closeLogoModal);
		document.querySelector('#modalCancelLogoBtn')?.addEventListener('click', closeLogoModal);

		document.querySelector('#tabLogoLightBtn')?.addEventListener('click', () => switchLogoTab('light'));
		document.querySelector('#tabLogoDarkBtn')?.addEventListener('click', () => switchLogoTab('dark'));
		document.querySelector('#tabLogoTextBtn')?.addEventListener('click', () => switchLogoTab('text'));

		// Light Logo Height Controls
		const syncLightHeight = val => {
			landingState.logo_height = String(val);
			const lRange = document.querySelector('#modalLogoHeightRange');
			const lNum = document.querySelector('#modalLogoHeightNumber');
			const lVal = document.querySelector('#modalLogoHeightVal');
			const preview = document.querySelector('#modalLogoPreviewImg');
			if (lRange) lRange.value = val;
			if (lNum) lNum.value = val;
			if (lVal) lVal.textContent = val + 'px';
			if (preview) preview.style.height = val + 'px';
		};
		document.querySelector('#modalLogoHeightRange')?.addEventListener('input', e => syncLightHeight(e.target.value));
		document.querySelector('#modalLogoHeightNumber')?.addEventListener('input', e => syncLightHeight(e.target.value));

		// Dark Logo Height Controls
		const syncDarkHeight = val => {
			landingState.logo_dark_height = String(val);
			const dRange = document.querySelector('#modalLogoDarkHeightRange');
			const dNum = document.querySelector('#modalLogoDarkHeightNumber');
			const dVal = document.querySelector('#modalLogoDarkHeightVal');
			const preview = document.querySelector('#modalLogoDarkPreviewImg');
			if (dRange) dRange.value = val;
			if (dNum) dNum.value = val;
			if (dVal) dVal.textContent = val + 'px';
			if (preview) preview.style.height = val + 'px';
		};
		document.querySelector('#modalLogoDarkHeightRange')?.addEventListener('input', e => syncDarkHeight(e.target.value));
		document.querySelector('#modalLogoDarkHeightNumber')?.addEventListener('input', e => syncDarkHeight(e.target.value));

		// Dark Logo Auto Invert Checkbox
		document.querySelector('#modalDarkAutoInvert')?.addEventListener('change', e => {
			landingState.logo_dark_invert = e.target.checked;
			const preview = document.querySelector('#modalLogoDarkPreviewImg');
			if (preview) preview.style.filter = e.target.checked ? 'brightness(0) invert(1)' : 'none';
		});

		// Copy Light to Dark
		document.querySelector('#modalCopyLightToDarkBtn')?.addEventListener('click', () => {
			if (!landingState.logo_image) {
				showToast('Primero sube un logotipo para fondo claro.', true);
				return;
			}
			landingState.logo_dark_image = landingState.logo_image;
			const pDarkImg = document.querySelector('#modalLogoDarkPreviewImg');
			if (pDarkImg) {
				pDarkImg.src = landingState.logo_image;
				pDarkImg.style.height = (landingState.logo_dark_height || 42) + 'px';
				pDarkImg.style.filter = landingState.logo_dark_invert ? 'brightness(0) invert(1)' : 'none';
			}
			document.querySelector('#modalLogoDarkPreviewWrap').style.display = 'block';
			document.querySelector('#modalLogoDarkPrompt').style.display = 'none';
			showToast('Logo del menú claro copiado al fondo oscuro.');
		});

		// File Reader Helper
		const fileToDataUrl = file => new Promise((resolve, reject) => {
			if (!file) return resolve('');
			if (file.size > 3 * 1024 * 1024) return reject(new Error('La imagen no puede superar 3 MB.'));
			const reader = new FileReader();
			reader.onload = () => resolve(reader.result);
			reader.onerror = () => reject(new Error('No se pudo leer el archivo.'));
			reader.readAsDataURL(file);
		});

		// Light Logo File Upload & Drag-Drop
		document.querySelector('#modalSelectFileBtn')?.addEventListener('click', () => document.querySelector('#modalLogoFileInput')?.click());
		document.querySelector('#modalChangeImgBtn')?.addEventListener('click', () => document.querySelector('#modalLogoFileInput')?.click());
		document.querySelector('#modalLogoFileInput')?.addEventListener('change', async e => {
			const file = e.target.files?.[0];
			if (!file) return;
			try {
				const dataUrl = await fileToDataUrl(file);
				landingState.logo_image = dataUrl;
				landingState.logo_type = 'image';
				const pImg = document.querySelector('#modalLogoPreviewImg');
				if (pImg) pImg.src = dataUrl;
				document.querySelector('#modalLogoPreviewWrap').style.display = 'block';
				document.querySelector('#modalLogoPrompt').style.display = 'none';
			} catch (err) {
				showToast(err.message, true);
			}
		});

		const dropBoxLight = document.querySelector('#modalLogoDropBox');
		if (dropBoxLight) {
			['dragenter', 'dragover'].forEach(n => dropBoxLight.addEventListener(n, e => { e.preventDefault(); dropBoxLight.classList.add('drag-over'); }));
			['dragleave', 'drop'].forEach(n => dropBoxLight.addEventListener(n, e => { e.preventDefault(); dropBoxLight.classList.remove('drag-over'); }));
			dropBoxLight.addEventListener('drop', async e => {
				const file = e.dataTransfer?.files?.[0];
				if (!file) return;
				try {
					const dataUrl = await fileToDataUrl(file);
					landingState.logo_image = dataUrl;
					landingState.logo_type = 'image';
					const pImg = document.querySelector('#modalLogoPreviewImg');
					if (pImg) pImg.src = dataUrl;
					document.querySelector('#modalLogoPreviewWrap').style.display = 'block';
					document.querySelector('#modalLogoPrompt').style.display = 'none';
				} catch (err) {
					showToast(err.message, true);
				}
			});
		}

		document.querySelector('#modalDeleteImgBtn')?.addEventListener('click', () => {
			landingState.logo_image = '';
			const pImg = document.querySelector('#modalLogoPreviewImg');
			if (pImg) pImg.src = '';
			document.querySelector('#modalLogoPreviewWrap').style.display = 'none';
			document.querySelector('#modalLogoPrompt').style.display = 'block';
			if (document.querySelector('#modalLogoFileInput')) document.querySelector('#modalLogoFileInput').value = '';
		});

		// Dark Logo File Upload & Drag-Drop
		document.querySelector('#modalSelectDarkFileBtn')?.addEventListener('click', () => document.querySelector('#modalLogoDarkFileInput')?.click());
		document.querySelector('#modalChangeDarkImgBtn')?.addEventListener('click', () => document.querySelector('#modalLogoDarkFileInput')?.click());
		document.querySelector('#modalLogoDarkFileInput')?.addEventListener('change', async e => {
			const file = e.target.files?.[0];
			if (!file) return;
			try {
				const dataUrl = await fileToDataUrl(file);
				landingState.logo_dark_image = dataUrl;
				landingState.logo_type = 'image';
				const pImg = document.querySelector('#modalLogoDarkPreviewImg');
				if (pImg) pImg.src = dataUrl;
				document.querySelector('#modalLogoDarkPreviewWrap').style.display = 'block';
				document.querySelector('#modalLogoDarkPrompt').style.display = 'none';
			} catch (err) {
				showToast(err.message, true);
			}
		});

		const dropBoxDark = document.querySelector('#modalLogoDarkDropBox');
		if (dropBoxDark) {
			['dragenter', 'dragover'].forEach(n => dropBoxDark.addEventListener(n, e => { e.preventDefault(); dropBoxDark.classList.add('drag-over'); }));
			['dragleave', 'drop'].forEach(n => dropBoxDark.addEventListener(n, e => { e.preventDefault(); dropBoxDark.classList.remove('drag-over'); }));
			dropBoxDark.addEventListener('drop', async e => {
				const file = e.dataTransfer?.files?.[0];
				if (!file) return;
				try {
					const dataUrl = await fileToDataUrl(file);
					landingState.logo_dark_image = dataUrl;
					landingState.logo_type = 'image';
					const pImg = document.querySelector('#modalLogoDarkPreviewImg');
					if (pImg) pImg.src = dataUrl;
					document.querySelector('#modalLogoDarkPreviewWrap').style.display = 'block';
					document.querySelector('#modalLogoDarkPrompt').style.display = 'none';
				} catch (err) {
					showToast(err.message, true);
				}
			});
		}

		document.querySelector('#modalDeleteDarkImgBtn')?.addEventListener('click', () => {
			landingState.logo_dark_image = '';
			const pImg = document.querySelector('#modalLogoDarkPreviewImg');
			if (pImg) pImg.src = '';
			document.querySelector('#modalLogoDarkPreviewWrap').style.display = 'none';
			document.querySelector('#modalLogoDarkPrompt').style.display = 'block';
			if (document.querySelector('#modalLogoDarkFileInput')) document.querySelector('#modalLogoDarkFileInput').value = '';
		});

		document.querySelector('#modalLogoTextInput')?.addEventListener('input', updateModalTextPreview);

		document.querySelector('#modalApplyLogoBtn')?.addEventListener('click', () => {
			landingState.logo_height = document.querySelector('#modalLogoHeightRange')?.value || '38';
			landingState.logo_dark_height = document.querySelector('#modalLogoDarkHeightRange')?.value || '42';
			landingState.logo_dark_invert = !!document.querySelector('#modalDarkAutoInvert')?.checked;

			const activeTab = document.querySelector('.modal-tabs .modal-tab-btn.active');
			if (activeTab && activeTab.id === 'tabLogoTextBtn') {
				landingState.logo_type = 'text';
				landingState.logo_text = document.querySelector('#modalLogoTextInput')?.value || 'smartisp';
				landingState.logo_badge = document.querySelector('#modalLogoBadgeInput2')?.value || '';
			} else {
				landingState.logo_type = 'image';
				landingState.logo_badge = document.querySelector('#modalLogoBadgeInput')?.value || '';
			}

			renderLandingLogo();
			markDirty(true);
			closeLogoModal();
			showToast('Logotipos actualizados en el editor.');
		});

		// =========================================================================
		// LOAD & SAVE ENGINE
		// =========================================================================
		const getAdminHeaders = () => {
			const headers = { 'Content-Type': 'application/json' };
			try {
				const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
				if (token) { headers.Authorization = `Bearer ${token}`; headers['X-Admin-Token'] = token; }
			} catch (e) { /* almacenamiento bloqueado: basta la cookie de sesión */ }
			return headers;
		};

		// Sin el contenido real cargado, guardar sobrescribiría la landing con la plantilla del editor.
		let landingLoaded = false;
		let savingLanding = false;
		const blockSaveAfterLoadError = message => {
			const saveBtn = document.querySelector('#saveLandingChangesBtn');
			if (saveBtn) { saveBtn.disabled = true; saveBtn.title = 'Recarga la página: el contenido actual no se pudo cargar.'; }
			const text = document.querySelector('#statusMessageText');
			if (text) text.textContent = 'No se pudo cargar el contenido. Guardar está bloqueado.';
			showToast(`No se pudo cargar el contenido de la landing: ${message}. Recarga la página antes de editar.`, true);
		};

		const loadLandingData = async () => {
			try {
				const headers = getAdminHeaders();
				let res = await fetch('/api/auth/landing-content', { credentials: 'include', headers });
				if (!res || !res.ok) {
					res = await fetch('/api/router.php?action=landing-content', { credentials: 'include', headers });
				}
				if (!res || !res.ok) throw new Error(`error ${res ? res.status : 'de red'}`);
				const data = await res.json();
				const content = Object.fromEntries((data.content || []).map(i => [i.key, i.value]));

				if (content.landing_logo_type) landingState.logo_type = content.landing_logo_type;
				if (content.landing_logo_image) landingState.logo_image = content.landing_logo_image;
				if (content.landing_logo_height) landingState.logo_height = content.landing_logo_height;
				if (content.landing_logo_dark_image) landingState.logo_dark_image = content.landing_logo_dark_image;
				if (content.landing_logo_dark_height) landingState.logo_dark_height = content.landing_logo_dark_height;
				if (content.landing_logo_dark_invert !== undefined) {
					landingState.logo_dark_invert = content.landing_logo_dark_invert === 'true' || content.landing_logo_dark_invert === true;
				}
				if (content.landing_logo_text) landingState.logo_text = content.landing_logo_text;
				if (content.landing_logo_badge) landingState.logo_badge = content.landing_logo_badge;

				renderLandingLogo();

				const healAdvantageIcons = (grid) => {
					const targetGrid = grid || document.querySelector('.advantages-grid');
					if (!targetGrid) return;
					const defaultIcons = ['shield-check', 'truck', 'cpu', 'credit-card', 'shield-check'];
					targetGrid.querySelectorAll('.advantage-card').forEach((card, idx) => {
						let iconWrap = card.querySelector('.advantage-icon');
						if (!iconWrap) {
							iconWrap = document.createElement('div');
							iconWrap.className = 'advantage-icon';
							const firstHeader = card.querySelector('h1, h2, h3, h4, h5, h6');
							if (firstHeader) firstHeader.before(iconWrap);
							else card.prepend(iconWrap);
						}
						const hasIcon = iconWrap.querySelector('svg, i[data-lucide]');
						if (!hasIcon) {
							const iconName = defaultIcons[idx % defaultIcons.length] || 'shield-check';
							iconWrap.innerHTML = `<i data-lucide="${iconName}" width="28"></i>`;
						}
					});
				};

				// Restore dynamic grids if saved
				const restoreGrid = (selector, htmlContent, addBtnId, addBtnText) => {
					if (!htmlContent) return;
					const container = document.querySelector(selector);
					if (!container) return;
					container.innerHTML = htmlContent;
					// Remove any lingering floating bars or slots
					container.querySelectorAll('.card-action-floating-bar, .add-card-slot, .card-action-btn').forEach(el => el.remove());
					// Add the add button slot at the end
					container.insertAdjacentHTML('beforeend', `<div class="add-card-slot"><button type="button" class="btn-add-grid-item" id="${addBtnId}"><i data-lucide="plus-circle" width="18"></i> <span>${addBtnText}</span></button></div>`);
					if (selector === '.advantages-grid') {
						healAdvantageIcons(container);
					}
					// Re-arm each card for editor interactivity
					container.querySelectorAll('.solution-card, .advantage-card, .value-box, .stat-item').forEach(card => {
						card.querySelectorAll('[data-landing-key], h1, h2, h3, h4, h5, h6, p, span, strong, a, li, .stat-number, .stat-label, .stat-desc').forEach(textEl => {
							if (textEl.closest('.card-action-floating-bar') || textEl.closest('.add-card-slot') || textEl.tagName === 'BUTTON' || textEl.tagName === 'I') return;
							textEl.contentEditable = 'true';
							textEl.spellcheck = false;
						});
						bindBoxEvents(card);
						if (card.classList.contains('solution-card')) {
							bindSolutionCardFeatures(card);
						}
					});
				};

				if (content.solutions_grid_html) restoreGrid('.solutions-grid', content.solutions_grid_html, 'addSolutionCardBtn', 'Agregar Nueva Línea de Solución');
				if (content.advantages_grid_html) restoreGrid('.advantages-grid', content.advantages_grid_html, 'addAdvantageCardBtn', 'Agregar Nueva Ventaja / Cuadro');
				if (content.about_visual_html) restoreGrid('.about-visual', content.about_visual_html, 'addValueBoxBtn', 'Agregar Nuevo Pilar / Cuadro');
				if (content.stats_grid_html) restoreGrid('.stats-grid', content.stats_grid_html, 'addStatItemBtn', 'Agregar Nueva Estadística');

				// Bind box events, features & editable
				document.querySelectorAll('.selectable-box').forEach(bindBoxEvents);
				document.querySelectorAll('.solution-card').forEach(bindSolutionCardFeatures);
				bindEditableEvents();
				healAdvantageIcons();
				lucide.createIcons();
				updateAdvGridCols();
				window.addEventListener('resize', updateAdvGridCols);

				// Apply texts & font sizes
				document.querySelectorAll('[data-landing-key]').forEach(el => {
					const k = el.dataset.landingKey;
					if (content[k] !== undefined && content[k] !== '') {
						if (k === 'hero_title' && content[k].includes('<span')) {
							el.innerHTML = content[k];
						} else {
							const icon = el.querySelector('i[data-lucide]');
							if (icon) {
								let textVal = '';
								el.childNodes.forEach(node => {
									if (node.nodeType === Node.TEXT_NODE) {
										textVal += node.textContent;
									}
								});
								el.textContent = textVal.trim();
							} else {
								el.textContent = content[k];
							}
						}
					}
					if (content[k + '_size']) el.style.fontSize = content[k + '_size'];
					if (content[k + '_color']) el.style.color = content[k + '_color'];
					if (content[k + '_weight']) el.style.fontWeight = content[k + '_weight'];
					if (content[k + '_align']) el.style.textAlign = content[k + '_align'];
				});

				syncWhatsAppLink();
				markDirty(false);
				landingLoaded = true;
			} catch (e) {
				console.warn('Error al cargar datos:', e);
				blockSaveAfterLoadError(e.message || 'error desconocido');
			}
		};

		const syncWhatsAppLink = () => {
			const waBtn = document.querySelector('.btn-whatsapp-direct');
			if (!waBtn) return;
			const textEl = waBtn.querySelector('[data-landing-key="contact_whatsapp_text"]') || waBtn;
			const raw = (textEl.textContent || '').replace(/[^\d+]/g, '');
			const clean = raw.replace(/\D/g, '');
			let num = '593983576667';
			if (clean.length >= 8) {
				if (clean.startsWith('09') && clean.length === 10) {
					num = '593' + clean.slice(1);
				} else if (clean.startsWith('593') && clean.length >= 11) {
					num = clean;
				} else if (clean.length === 9 && clean.startsWith('9')) {
					num = '593' + clean;
				} else {
					num = clean;
				}
			}
			waBtn.href = `https://wa.me/${num}?text=${encodeURIComponent('Hola SmartISP, deseo más información sobre sus soluciones y equipos.')}`;
		};
		syncWhatsAppLink();
		document.querySelector('[data-landing-key="contact_whatsapp_text"]')?.addEventListener('input', syncWhatsAppLink);
		document.querySelector('.btn-whatsapp-direct')?.addEventListener('click', syncWhatsAppLink);

		const cleanGridHtml = selector => {
			const container = document.querySelector(selector);
			if (!container) return '';
			const clone = container.cloneNode(true);
			// 1. Remover barras flotantes, botones, slots y toolbars del editor
			clone.querySelectorAll('.add-card-slot, .card-action-floating-bar, .card-action-btn, .editor-inline-toolbar, .editor-top-bar, .btn-add-feature, .btn-delete-feature').forEach(s => s.remove());
			// 2. Remover clases de selección y foco del editor
			clone.querySelectorAll('.is-card-selected').forEach(c => c.classList.remove('is-card-selected'));
			clone.querySelectorAll('.selectable-box').forEach(c => c.classList.remove('selectable-box'));
			clone.querySelectorAll('.active-editable-focus').forEach(c => c.classList.remove('active-editable-focus'));
			// 3. Remover atributos de edición
			clone.querySelectorAll('[data-deletable-box]').forEach(c => c.removeAttribute('data-deletable-box'));
			clone.querySelectorAll('[contenteditable]').forEach(c => c.removeAttribute('contenteditable'));
			clone.querySelectorAll('[spellcheck]').forEach(c => c.removeAttribute('spellcheck'));
			return clone.innerHTML.trim();
		};

		const saveLandingData = async () => {
			if (savingLanding) return;
			if (!landingLoaded) { showToast('El contenido actual no se cargó: recarga la página antes de guardar.', true); return; }
			savingLanding = true;
			const saveBtn = document.querySelector('#saveLandingChangesBtn');
			const originalHtml = saveBtn.innerHTML;
			saveBtn.disabled = true;
			saveBtn.innerHTML = '<span>Guardando...</span>';

			// Deselect any active card box
			setSelectedCardBox(null);

			const payload = {
				landing_logo_type: landingState.logo_type,
				landing_logo_image: landingState.logo_image,
				landing_logo_height: landingState.logo_height,
				landing_logo_dark_image: landingState.logo_dark_image,
				landing_logo_dark_height: landingState.logo_dark_height,
				landing_logo_dark_invert: landingState.logo_dark_invert ? 'true' : 'false',
				landing_logo_text: landingState.logo_text,
				landing_logo_badge: landingState.logo_badge,
				solutions_grid_html: cleanGridHtml('.solutions-grid'),
				advantages_grid_html: cleanGridHtml('.advantages-grid'),
				about_visual_html: cleanGridHtml('.about-visual'),
				stats_grid_html: cleanGridHtml('.stats-grid')
			};

			document.querySelectorAll('[data-landing-key]').forEach(el => {
				const k = el.dataset.landingKey;
				if (k === 'hero_title') {
					payload[k] = el.innerHTML.trim();
				} else {
					const icon = el.querySelector('i[data-lucide]');
					if (icon) {
						let textVal = '';
						el.childNodes.forEach(node => {
							if (node.nodeType === Node.TEXT_NODE) textVal += node.textContent;
						});
						payload[k] = textVal.trim();
					} else {
						payload[k] = el.textContent.trim();
					}
				}
				if (el.style.fontSize) payload[k + '_size'] = el.style.fontSize;
				if (el.style.color) payload[k + '_color'] = el.style.color;
				if (el.style.fontWeight) payload[k + '_weight'] = el.style.fontWeight;
				if (el.style.textAlign) payload[k + '_align'] = el.style.textAlign;
			});

			try {
				const headers = getAdminHeaders();
				let response = await fetch('/api/auth/landing-content', {
					method: 'POST',
					credentials: 'include',
					headers,
					body: JSON.stringify(payload)
				});
				if (!response || !response.ok) {
					response = await fetch('/api/router.php?action=landing-content', {
						method: 'POST',
						credentials: 'include',
						headers,
						body: JSON.stringify(payload)
					});
				}
				const result = await response.json();
				if (!response.ok) throw new Error(result.error || 'No se pudo guardar.');
				markDirty(false);
				showToast('¡Página de presentación guardada con éxito!');
			} catch (err) {
				showToast(err.message, true);
			} finally {
				savingLanding = false;
				saveBtn.disabled = false;
				saveBtn.innerHTML = originalHtml;
				markDirty(isDirty);
			}
		};

		document.querySelector('#saveLandingChangesBtn')?.addEventListener('click', saveLandingData);

		document.addEventListener('keydown', e => {
			if ((e.ctrlKey || e.metaKey) && e.key === 's') {
				e.preventDefault();
				saveLandingData();
			}
		});

		document.querySelector('#resetLandingDefaultsBtn')?.addEventListener('click', async () => {
			if (!confirm('¿Restablecer todos los textos, cuadros y logotipo a los valores originales de fábrica?')) {
				return;
			}
			try {
				const headers = getAdminHeaders();
				let res = await fetch('/api/auth/landing-content/reset', { method: 'POST', credentials: 'include', headers, body: '{}' });
				if (!res.ok && res.status !== 401 && res.status !== 403) {
					res = await fetch('/api/router.php?action=landing-content-reset', { method: 'POST', credentials: 'include', headers, body: '{}' });
				}
				if (!res.ok) {
					const data = await res.json().catch(() => ({}));
					throw new Error(data.error || 'Error al restablecer.');
				}
				isDirty = false;
				showToast('Valores originales restablecidos. Recargando...');
				setTimeout(() => { location.reload(); }, 700);
			} catch (err) {
				showToast(err.message, true);
			}
		});

		loadLandingData();
