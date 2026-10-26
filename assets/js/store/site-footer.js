// Pie de pagina compartido (nosotros.html, servicios.html...). Misma logica que
// renderFooterContent() en tienda-page.js, pero autonoma: pide su propio /api/auth/landing-content
// en vez de reusar el "content" que ya tienen las paginas con hero propio.
(function () {
	const renderFooterContent = content => {
		if (!content) return;
		if (content.footer_text) {
			const brandP = document.querySelector('.site-footer-brand p');
			if (brandP) brandP.textContent = content.footer_text;
		}
		const whatsapp = (content.footer_whatsapp || '').trim();
		if (whatsapp) {
			const digits = whatsapp.replace(/[^\d]/g, '');
			const waHref = `https://wa.me/${digits}`;
			const waLink = document.querySelector('#footerWhatsappLink');
			if (waLink) { waLink.href = waHref; waLink.target = '_blank'; waLink.rel = 'noopener'; waLink.hidden = false; document.querySelector('#footerWhatsappText').textContent = whatsapp; }
			const waCta = document.querySelector('#footerWhatsappCta');
			const waCard = document.querySelector('#footerWhatsappCard');
			if (waCta) { waCta.href = waHref; waCta.target = '_blank'; waCta.rel = 'noopener'; }
			if (waCard) waCard.hidden = false;
		}
		const email = (content.footer_email || '').trim();
		if (email) {
			const emailLink = document.querySelector('#footerEmailLink');
			if (emailLink) { emailLink.href = `mailto:${email}`; emailLink.hidden = false; document.querySelector('#footerEmailText').textContent = email; }
		}
		const address = (content.footer_address || '').trim();
		if (address) {
			const addressItem = document.querySelector('#footerAddressItem');
			if (addressItem) { addressItem.hidden = false; document.querySelector('#footerAddressText').textContent = address; }
		}
		const socials = [
			['footer_social_instagram', '#footerSocialInstagram'],
			['footer_social_facebook', '#footerSocialFacebook'],
			['footer_social_youtube', '#footerSocialYoutube'],
			['footer_social_linkedin', '#footerSocialLinkedin']
		];
		socials.forEach(([key, selector]) => {
			const url = (content[key] || '').trim();
			const el = document.querySelector(selector);
			if (el && url) { el.href = url; el.target = '_blank'; el.rel = 'noopener'; el.hidden = false; }
		});
		const copyright = document.querySelector('#footerCopyright');
		if (copyright) copyright.textContent = `© ${new Date().getFullYear()} SMART-ISP. Todos los derechos reservados.`;
		window.lucide?.createIcons();
	};
	const init = () => {
		window.SmartLandingContent = fetch('/api/auth/landing-content', { cache: 'no-store' })
			.then(r => r.ok ? r.json() : null)
			.then(data => {
				if (!data) return {};
				const content = Object.fromEntries((data.content || []).map(item => [item.key, item.value]));
				renderFooterContent(content);
				return content;
			})
			.catch(error => {
				console.warn('No se pudo cargar el contenido del pie de pagina:', error);
				return {};
			});
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
