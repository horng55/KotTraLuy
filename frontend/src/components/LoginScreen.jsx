import { messageText } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { ChevronRight } from 'lucide-react';
import ThemeSelector from './ThemeSelector';
import '../login-screen.css';
import BrandLogo from './BrandLogo';

export default function LoginScreen({ mode, setMode, form, setForm, submit, loading, error, language, toggleLanguage }) {
  const login = mode === 'login';
  const update = (key) => (event) => setForm({ ...form, [key]: event.target.value });
  return <main className="login-screen">
    <section className="login-card" aria-labelledby="login-title">
      <div className="login-header">
        <BrandLogo className="login-logo" />
        <button className="login-language" onClick={toggleLanguage} aria-label={language === 'en' ? tr("common.switch_to_khmer") : tr("common.switch_to_english")}><span className={language === 'km' ? 'active' : ''}>{'\u1781\u17d2\u1798\u17c2\u179a'}</span><span>/</span><span className={language === 'en' ? 'active' : ''}>English</span></button>
      </div>
      <div className="login-theme-control"><ThemeSelector /></div>
      <p className="login-eyebrow">{tr("financing.sme_financing_preparation")}</p>
      <h1 id="login-title">{login ? tr("auth.sign_in_to_kottraluy") : tr("settings.create_your_kottraluy_workspace")}</h1>
      <p className="login-subtitle">{login ? tr("financing.keep_your_business_records_organized_and_ready_for_your_next_funding_conversation") : tr("financing.set_up_a_workspace_to_organize_your_records_and_prepare_for_provider_review")}</p>
      <form onSubmit={submit} className="login-form">
        {!login && <><label htmlFor="login-name">{tr("common.your_name")}</label><input id="login-name" value={form.name} onChange={update('name')} required /><label htmlFor="login-business">{tr("common.business_name_2")}</label><input id="login-business" value={form.business_name} onChange={update('business_name')} required /></>}
        <label htmlFor="login-email">{tr("common.email")}</label><input id="login-email" type="email" value={form.email} onChange={update('email')} required />
        <label htmlFor="login-password">{tr("auth.password")}</label><input id="login-password" type="password" value={form.password} onChange={update('password')} required />
        {error && <p className="login-error">{messageText(error)}</p>}
        <button className="login-submit" disabled={loading}>{loading ? tr("common.please_wait_2") : login ? tr("auth.sign_in") : tr("settings.create_workspace")} <ChevronRight size={16} /></button>
      </form>
      <button className="login-mode" onClick={() => setMode(login ? 'register' : 'login')}>{login ? tr("settings.create_a_new_workspace") : tr("auth.already_have_a_workspace_sign_in")}</button>
    </section>
  </main>;
}
