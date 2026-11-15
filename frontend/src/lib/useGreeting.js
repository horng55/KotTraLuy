import { useEffect, useState } from 'react';
import { t } from './i18n.js';

const DEFAULT_TIME_ZONE = 'Asia/Phnom_Penh';

export function greetingKey(date = new Date(), timeZone = DEFAULT_TIME_ZONE) {
  let hour;
  try {
    const parts = new Intl.DateTimeFormat('en-US', { hour: 'numeric', hourCycle: 'h23', timeZone }).formatToParts(date);
    hour = Number(parts.find(part => part.type === 'hour')?.value);
  } catch {
    const parts = new Intl.DateTimeFormat('en-US', { hour: 'numeric', hourCycle: 'h23', timeZone: DEFAULT_TIME_ZONE }).formatToParts(date);
    hour = Number(parts.find(part => part.type === 'hour')?.value);
  }
  if (!Number.isFinite(hour)) return 'common.welcome';
  if (hour >= 5 && hour < 12) return 'common.good_morning';
  if (hour >= 12 && hour < 18) return 'common.good_afternoon';
  if (hour >= 18 && hour < 22) return 'common.good_evening';
  return 'common.good_night';
}

export function useGreeting(language = 'en', timeZone = DEFAULT_TIME_ZONE) {
  const [, refresh] = useState(0);
  useEffect(() => {
    const timer = window.setInterval(() => refresh(value => value + 1), 60_000);
    return () => window.clearInterval(timer);
  }, []);
  return t(greetingKey(new Date(), timeZone), {}, language);
}

export const greetingTimeZone = user => user?.timezone || user?.time_zone || user?.business?.timezone || DEFAULT_TIME_ZONE;
