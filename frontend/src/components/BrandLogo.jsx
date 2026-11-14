import { useId } from 'react';
import { useTheme } from '../lib/theme';
import transparentArtwork from '../assets/kottraluy-login-logo.png';

// Use the existing artwork's alpha channel, not an inversion or a redrawn logo.
// The viewBox trims only fully transparent margins; artwork proportions are unchanged.
export default function BrandLogo({ className = 'sidebar-brand-logo', white = false, compact = false }) {
  const maskId = useId();
  const { resolved } = useTheme();
  return <svg className={className} data-variant={resolved} viewBox={compact ? '1 193 500 390' : '1 193 1365 390'} width={compact ? 42 : 155} height={compact ? 42 : 44.2857} role="img" aria-label="KotTraLuy" preserveAspectRatio="xMinYMid meet">
    <defs><mask id={maskId} x="1" y="193" width="1365" height="390" maskUnits="userSpaceOnUse" style={{ maskType: 'alpha' }}><image href={transparentArtwork} x="0" y="0" width="1366" height="768" /></mask></defs>
    <rect x="1" y="193" width="1365" height="390" fill={white ? '#FFFFFF' : 'currentColor'} mask={`url(#${maskId})`} />
  </svg>;
}
