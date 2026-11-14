import { Camera, ImagePlus, LoaderCircle, RefreshCw, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { apiDownload } from '../lib/api';
import { text } from '../lib/i18n';

export function ProductImage({ product, language = 'en', onChange, disabled = false }) {
  const input = useRef(null); const camera = useRef(null);
  const [preview, setPreview] = useState(null); const [loading, setLoading] = useState(Boolean(product?.image_available)); const [error, setError] = useState('');
  const tr = key => text(key, language);
  useEffect(() => {
    if (!product?.id || !product.image_available) return;
    let url; const controller = new AbortController();
    const load = async () => {
      try {
        const { blob } = await apiDownload(`/products/${product.id}/image`, { signal: controller.signal });
        if (controller.signal.aborted) return;
        url = URL.createObjectURL(blob); setPreview(url);
      } catch {
        if (!controller.signal.aborted) setError(text('productImage.loadError', language));
      } finally { if (!controller.signal.aborted) setLoading(false); }
    };
    load();
    return () => { controller.abort(); if (url) URL.revokeObjectURL(url); };
  }, [product?.id, product?.image_available, language]);
  const select = file => {
    if (!file) return; setError('');
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return setError(tr('productImage.invalidType'));
    if (file.size > 5 * 1024 * 1024) return setError(tr('productImage.tooLarge'));
    // The server validates and optimizes images, including on older WebViews.
    setPreview(URL.createObjectURL(file)); onChange({ file, remove: false });
  };
  const remove = () => { setPreview(null); setError(''); onChange({ file: null, remove: true }); };
  const open = ref => ref.current?.click();
  return <div className="kt-field product-image-field"><label>{tr('productImage.label')}</label><div className="product-image-drop" onDragOver={event => { event.preventDefault(); }} onDrop={event => { event.preventDefault(); if (!disabled) select(event.dataTransfer.files?.[0]); }}>
    {loading ? <div className="product-image-state"><LoaderCircle className="kt-spin" size={28}/><span>{tr('productImage.loading')}</span></div> : preview ? <div className="product-image-preview-wrap"><img src={preview} alt={tr('productImage.label')} className="product-image-preview"/><div className="product-image-actions"><button type="button" className="kt-button kt-secondary" onClick={() => open(input)} disabled={disabled}><RefreshCw size={15}/>{tr('productImage.change')}</button><button type="button" className="kt-button kt-secondary" onClick={remove} disabled={disabled}><X size={15}/>{tr('productImage.remove')}</button></div></div> : <div className="product-image-state"><ImagePlus size={30}/><strong>{tr('productImage.instructions')}</strong><small>{tr('productImage.formats')}</small><small>{tr('productImage.maxSize')}</small></div>}
  </div><div className="product-image-buttons"><button type="button" className="kt-button kt-secondary" onClick={() => open(input)} disabled={disabled}><Upload size={15}/>{tr('productImage.upload')}</button><button type="button" className="kt-button kt-secondary" onClick={() => open(camera)} disabled={disabled}><Camera size={15}/>{tr('productImage.camera')}</button><input ref={input} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={event => select(event.target.files?.[0])}/><input ref={camera} type="file" accept="image/jpeg,image/png,image/webp" capture="environment" hidden onChange={event => select(event.target.files?.[0])}/></div>{error && <p className="kt-field-error">{error}</p>}</div>;
}

export function ProductThumbnail({ product, size = 44, alt = '' }) {
  const [src, setSrc] = useState('');
  useEffect(() => {
    if (!product?.id || !product.image_available) return;
    let url; const controller = new AbortController();
    apiDownload(`/products/${product.id}/image`, { signal: controller.signal })
      .then(({ blob }) => { if (!controller.signal.aborted) { url = URL.createObjectURL(blob); setSrc({ id: product.id, url }); } })
      .catch(failure => { if (failure.name !== 'AbortError') console.error('Product image could not be loaded', failure); });
    return () => { controller.abort(); if (url) URL.revokeObjectURL(url); };
  }, [product?.id, product?.image_available]);
  return product?.image_available && src.id === product?.id ? <img className="product-thumbnail" style={{ width: size, height: size }} src={src.url} alt={alt || product?.name || ''}/> : <span className="product-thumbnail-placeholder" style={{ width: size, height: size }} aria-hidden="true"><ImagePlus size={Math.max(16, Math.round(size * .38))}/></span>;
}
