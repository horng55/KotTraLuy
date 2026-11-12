/**
 * Small, defensive Telegram Mini App adapter. All methods are no-ops in a
 * normal browser so the existing web application keeps the same behaviour.
 */
export function getTelegramWebApp() {
  return typeof window !== 'undefined' ? window.Telegram?.WebApp || null : null;
}

export function isTelegramMiniApp(webApp = getTelegramWebApp()) {
  return Boolean(webApp?.initData);
}

export function syncTelegramEnvironment(webApp = getTelegramWebApp()) {
  if (!webApp) return null;
  webApp.ready?.();
  webApp.expand?.();
  const root = document.documentElement;
  root.dataset.telegramMiniApp = isTelegramMiniApp(webApp) ? 'true' : 'false';
  if (webApp.colorScheme === 'dark' || webApp.colorScheme === 'light') {
    root.dataset.telegramColorScheme = webApp.colorScheme;
  }
  const safe = webApp.safeAreaInset || {};
  const content = webApp.contentSafeAreaInset || {};
  root.style.setProperty('--telegram-safe-top', `${Math.max(safe.top || 0, content.top || 0)}px`);
  root.style.setProperty('--telegram-safe-right', `${Math.max(safe.right || 0, content.right || 0)}px`);
  root.style.setProperty('--telegram-safe-bottom', `${Math.max(safe.bottom || 0, content.bottom || 0)}px`);
  root.style.setProperty('--telegram-safe-left', `${Math.max(safe.left || 0, content.left || 0)}px`);
  return webApp;
}

export function setTelegramBackButton(visible, onBack, webApp = getTelegramWebApp()) {
  const button = webApp?.BackButton;
  if (!button) return () => {};
  const previous = button.__kottraluyHandler;
  if (previous) button.offClick?.(previous);
  const handler = () => onBack?.();
  button.__kottraluyHandler = handler;
  if (visible) {
    button.onClick?.(handler);
    button.show?.();
  } else {
    button.hide?.();
  }
  return () => {
    button.offClick?.(handler);
    if (button.__kottraluyHandler === handler) delete button.__kottraluyHandler;
    button.hide?.();
  };
}

export function telegramHaptic(type = 'light', webApp = getTelegramWebApp()) {
  const feedback = webApp?.HapticFeedback;
  if (!feedback) return;
  if (type === 'success' || type === 'error' || type === 'warning') feedback.notificationOccurred?.(type);
  else feedback.impactOccurred?.(type);
}
