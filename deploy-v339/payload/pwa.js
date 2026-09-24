(function () {
  'use strict';
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  let installPrompt = null;

  function injectStyles() {
    if (document.getElementById('avr-pwa-style')) return;
    const style = document.createElement('style');
    style.id = 'avr-pwa-style';
    style.textContent = '@media(display-mode:standalone){body{padding-bottom:env(safe-area-inset-bottom)!important}}.avr-pwa-toast{position:fixed;left:16px;right:16px;bottom:calc(18px + env(safe-area-inset-bottom));z-index:2147483001;max-width:520px;margin:auto;padding:13px 15px;border:1px solid #3d4147;border-radius:10px;background:#111317;color:#fff;font:14px Arial,sans-serif;box-shadow:0 10px 35px #000a}';
    document.head.appendChild(style);
  }

  function toast(message) {
    injectStyles();
    document.querySelector('.avr-pwa-toast')?.remove();
    const node = document.createElement('div');
    node.className = 'avr-pwa-toast';
    node.textContent = message;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 4500);
  }

  async function showInstall(targetId) {
    if (isStandalone) return;
    if (installPrompt) {
      installPrompt.prompt();
      await installPrompt.userChoice;
      installPrompt = null;
      return;
    }
    const target = targetId ? document.getElementById(targetId) : document;
    const hint = target?.querySelector('.pwaHint, .iosHint');
    const message = isIOS
      ? 'В Safari нажмите «Поделиться» и выберите «На экран Домой».'
      : 'Откройте меню браузера и выберите «Установить приложение» или «Добавить на главный экран».';
    if (hint) hint.textContent = message;
    toast(message);
  }

  async function register() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return null;
    try { return await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }); }
    catch (_) { return null; }
  }

  async function keepAwake(enabled) {
    if (!('wakeLock' in navigator)) return false;
    if (!enabled) {
      try { await window.__avrWakeLock?.release(); } catch (_) {}
      window.__avrWakeLock = null;
      return true;
    }
    try { window.__avrWakeLock = await navigator.wakeLock.request('screen'); return true; }
    catch (_) { return false; }
  }

  function decodeKey(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const binary = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(binary, char => char.charCodeAt(0));
  }

  async function enablePush(token) {
    if (!('Notification' in window) || !('PushManager' in window) || !window.isSecureContext) {
      throw new Error('Системные уведомления не поддерживаются на этом устройстве');
    }
    if (isIOS && !isStandalone) {
      throw new Error('Сначала установите AV Rescue: Safari → Поделиться → На экран Домой');
    }
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Разрешение на уведомления не выдано');
    const registration = await register();
    if (!registration) throw new Error('Не удалось запустить службу уведомлений');
    const configResponse = await fetch('/api/push_config.php', { cache: 'no-store' });
    const config = await configResponse.json();
    if (!config.ok || !config.public_key) throw new Error('Уведомления ещё не настроены на сервере');
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: decodeKey(config.public_key)
      });
    }
    const response = await fetch('/api/push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token || '', subscription: subscription.toJSON() })
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось включить уведомления');
    localStorage.setItem('avr_push_enabled', '1');
    return true;
  }

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && window.__avrKeepAwakeWanted) keepAwake(true);
  });
  injectStyles();

  window.AVRPWA = {
    standalone: isStandalone,
    install: showInstall,
    toast,
    pushEnabled: () => 'Notification' in window && Notification.permission === 'granted' && localStorage.getItem('avr_push_enabled') === '1',
    enablePush,
    keepAwake: async enabled => {
      window.__avrKeepAwakeWanted = !!enabled;
      return keepAwake(enabled);
    },
    ready: register()
  };
})();
