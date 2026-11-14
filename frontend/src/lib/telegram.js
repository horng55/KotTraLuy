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

export function telegramDownloadFile(url, filename, webApp = getTelegramWebApp()) {
  if (!isTelegramMiniApp(webApp)) return false;
  const target = new URL(url, window.location.href);
  if (target.protocol !== 'https:') return false;
  const major = Number(String(webApp.version || '0').split('.')[0]);
  if (major >= 8 && typeof webApp.downloadFile === 'function') {
    try {
      webApp.downloadFile({ url: target.href, file_name: filename });
      return true;
    } catch { /* Older clients can open the temporary download in a browser. */ }
  }
  if (typeof webApp.openLink === 'function') {
    webApp.openLink(target.href);
    return true;
  }
  return false;
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
  if (!feedback) return false;
  const [major, minor] = String(webApp?.version || '0.0').split('.').map(Number);
  if (!Number.isFinite(major) || !Number.isFinite(minor) || major < 6 || (major === 6 && minor < 1)) return false;
  try {
    if (type === 'success' || type === 'error' || type === 'warning') {
      if (typeof feedback.notificationOccurred !== 'function') return false;
      feedback.notificationOccurred(type);
    } else {
      if (typeof feedback.impactOccurred !== 'function') return false;
      feedback.impactOccurred(type);
    }
    return true;
  } catch {
    // Haptics are optional and some Telegram WebView versions reject them.
    return false;
  }
}
