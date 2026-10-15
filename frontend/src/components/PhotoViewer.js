import {useEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {alpha, useTheme} from '@mui/material/styles';
import {TransformComponent, TransformWrapper} from 'react-zoom-pan-pinch';
import MediaImage from './MediaImage';
import {formatDate} from '../subjects/labels';
import './PhotoViewer.css';

const MAX_SCALE = 10;
const ZOOM_LEVELS = [1, 2.5, 4, 8];
const ZOOM_ANIMATION_MS = 200;

/**
 * Fotografiile unui subiect pe tot ecranul (preluat din ArtaNFT, PhotoModal): pinch centrat pe degete, rotița,
 * dublu-click/tap comută mărirea, nivelurile 1× / 2,5× / 4× / 8×, pan cu limite (mouse și touch). Anterioara/
 * următoarea: săgețile, ← → sau swipe - swipe doar când poza nu e mărită. Închidere cu × sau Esc; focusul
 * rămâne în fereastră și revine apoi unde era. `index` null = închis. Culorile urmează tema activă.
 */
export default function PhotoViewer({photos, index, onIndex, onClose, isPublic, title, actions}) {
    const count = photos.length;
    const open = index !== null && index !== undefined && count > 0;
    if (!open) return null;
    const i = Math.min(index, count - 1);
    return <Viewer photos={photos} i={i} count={count} onIndex={onIndex} onClose={onClose} isPublic={isPublic} title={title} actions={actions}/>;
}

function Viewer({photos, i, count, onIndex, onClose, isPublic, title, actions}) {
    const {palette} = useTheme();
    const dialog = useRef(null);
    const closeButton = useRef(null);
    const zoom = useRef(null);
    const current = useRef({onClose, onIndex, i, count});
    current.current = {onClose, onIndex, i, count};
    const [scale, setScale] = useState(1);
    const pointers = useRef(new Set());
    const swipe = useRef(null);

    // Fiecare fotografie se deschide nemărită.
    useEffect(() => { zoom.current?.resetTransform(0); setScale(1); }, [i]);

    useEffect(() => {
        const previousFocus = document.activeElement;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        closeButton.current?.focus();
        const onKey = (event) => {
            const c = current.current;
            if (event.key === 'Escape') { event.preventDefault(); c.onClose(); }
            if (event.key === 'ArrowRight' && c.count > 1) { event.preventDefault(); c.onIndex((c.i + 1) % c.count); }
            if (event.key === 'ArrowLeft' && c.count > 1) { event.preventDefault(); c.onIndex((c.i - 1 + c.count) % c.count); }
            if (event.key === 'Tab' && dialog.current) {
                const buttons = [...dialog.current.querySelectorAll('button:not(:disabled)')];
                const first = buttons[0];
                const last = buttons[buttons.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
            if (previousFocus?.isConnected) previousFocus.focus();
        };
    }, []);

    const go = (step) => onIndex((i + step + count) % count);

    // Swipe orizontal = anterioara/următoarea (circular), doar nemărită și cu un singur deget.
    const swipeStart = (event) => {
        pointers.current.add(event.pointerId);
        swipe.current = scale > 1.01 || pointers.current.size > 1 ? null : {x: event.clientX, y: event.clientY};
    };
    const swipeEnd = (event) => {
        pointers.current.delete(event.pointerId);
        const start = swipe.current;
        swipe.current = null;
        if (!start || count < 2) return;
        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;
        if (Math.abs(dx) >= 50 && Math.abs(dx) > Math.abs(dy) * 1.2) go(dx < 0 ? 1 : -1);
    };
    const swipeCancel = (event) => { pointers.current.delete(event.pointerId); swipe.current = null; };

    const setZoomLevel = (level) => {
        const api = zoom.current;
        if (level === 1) api?.resetTransform(ZOOM_ANIMATION_MS);
        else api?.centerView(level, ZOOM_ANIMATION_MS);
        setScale(level);
    };

    const photo = photos[i];
    const themeVars = {
        '--modal-bg': alpha(palette.background.default, 0.97),
        '--modal-text': palette.text.primary,
        '--modal-btn-bg': palette.background.paper,
        '--modal-btn-border': palette.divider,
        '--modal-accent': palette.primary.main,
        '--modal-accent-text': palette.primary.contrastText,
        '--modal-warning': palette.warning.main,
        colorScheme: palette.mode,
    };

    return createPortal(
        <div className="photo-modal" ref={dialog} role="dialog" aria-modal="true" style={themeVars}
             aria-label={`Fotografii: ${title}`} onClick={(event) => event.stopPropagation()}>
            <button ref={closeButton} type="button" className="photo-modal-close" aria-label="Închide" onClick={onClose}>
                <span aria-hidden="true">×</span>
            </button>
            <div className="photo-modal-zoom-controls" role="group" aria-label="Nivel de mărire">
                {ZOOM_LEVELS.map((level) => (
                    <button key={level} type="button" className={Math.abs(scale - level) < 0.05 ? 'active' : ''}
                            aria-pressed={Math.abs(scale - level) < 0.05} onClick={() => setZoomLevel(level)}>
                        {String(level).replace('.', ',')}×
                    </button>
                ))}
            </div>
            <figure className={`photo-modal-zoomable${scale > 1.01 ? ' zoomed' : ''}`}
                    onPointerDown={swipeStart} onPointerUp={swipeEnd} onPointerCancel={swipeCancel}>
                <TransformWrapper ref={zoom} minScale={1} maxScale={MAX_SCALE} limitToBounds centerZoomedOut
                                  doubleClick={{mode: 'toggle', step: 1.5}} wheel={{step: 0.15}}
                                  panning={{disabled: scale <= 1.01, velocityDisabled: true}}
                                  onTransformed={(_, state) => setScale(state.scale)}>
                    <TransformComponent wrapperClass="photo-modal-viewport" contentClass="photo-modal-content">
                        <MediaImage key={photo.id} item={photo} variant="display" isPublic={isPublic(photo)} draggable={false}
                                    alt={`Fotografia ${i + 1} din ${count} la „${title}”`}
                                    sx={{width: '100vw', height: '100dvh', objectFit: 'contain'}}/>
                    </TransformComponent>
                </TransformWrapper>
                <figcaption aria-live="polite">
                    {count > 1 ? `${i + 1} / ${count} · ` : ''}Adăugată {formatDate(photo.createdAt)}
                    {photo.status === 'PENDING' && <span className="photo-modal-pending"> · În așteptarea aprobării</span>}
                </figcaption>
            </figure>
            {actions && <div className="photo-modal-actions">{actions(photo)}</div>}
            {count > 1 && <>
                <button type="button" className="photo-modal-prev" aria-label="Fotografia anterioară" onClick={() => go(-1)}>‹</button>
                <button type="button" className="photo-modal-next" aria-label="Fotografia următoare" onClick={() => go(1)}>›</button>
            </>}
        </div>, document.body,
    );
}
