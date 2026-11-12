import { createContext, useContext } from 'react';

export const NotificationContext = createContext({ unreadCount: 0, revision: 0, enabled: false, error: '', mutating: false });
export const useNotifications = () => useContext(NotificationContext);
