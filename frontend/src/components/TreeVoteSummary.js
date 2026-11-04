import {useEffect, useState} from 'react';
import {Box, ButtonBase, Rating, Stack, Tooltip, Typography} from '@mui/material';
import StarRoundedIcon from '@mui/icons-material/StarRounded';
import ThumbUpOutlinedIcon from '@mui/icons-material/ThumbUpOutlined';
import ThumbDownOutlinedIcon from '@mui/icons-material/ThumbDownOutlined';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import {fetchVotes} from '../api/votes';

/** „1 vot”, „5 voturi”, „20 de voturi”, „101 voturi” (prepoziția „de” după 20+, cu excepția terminațiilor 01–19). */
export const votesLabel = (n) => (n === 1 ? '1 vot' : `${n}${n !== 0 && (n % 100 === 0 || n % 100 >= 20) ? ' de' : ''} voturi`);

/**
 * variant="tree" (implicit): rezumatul compact din lista arborelui.
 * variant="header": rândul de sub titlul subiectului - stele, număr de voturi, îndemn; clic => onVote (derulare la Voturi).
 */
export default function TreeVoteSummary({subjectId, variant = 'tree', onVote}) {
    const [summary, setSummary] = useState(null);
    useEffect(() => {
        let active = true;
        let updated = false;
        setSummary(null);
        fetchVotes(subjectId).then((data) => { if (active && !updated) setSummary(data); }).catch(() => {});
        const update = ({detail}) => {
            if (detail.subjectId === subjectId) { updated = true; setSummary(detail.summary); }
        };
        window.addEventListener('subject-votes-updated', update);
        return () => { active = false; window.removeEventListener('subject-votes-updated', update); };
    }, [subjectId]);
    if (!Array.isArray(summary?.types)) return null;
    const importance = summary.types.find((t) => t.code === 'IMPORTANCE');
    const binary = summary.types.find((t) => t.code === 'VALIDITY' || t.code === 'YES_NO');
    // ADR-0013: la sondaje, doar numărul participanților (rezultatele pot fi ascunse până la vot).
    const choice = summary.types.find((t) => t.code === 'CHOICE');
    const positive = binary?.code === 'YES_NO' ? 'susțineri' : 'validări';
    const negative = binary?.code === 'YES_NO' ? 'voturi împotrivă' : 'invalidări';
    const average = importance?.result.total > 0
        ? Object.entries(importance.result.counts).reduce((sum, [v, n]) => sum + Number(v) * n, 0) / importance.result.total : null;
    const averageText = average === null ? null : `${average.toLocaleString('ro-RO', {maximumFractionDigits: 1})}/5`;
    if (variant === 'header') {
        const total = importance?.result.total ?? binary?.result.total ?? choice?.result.total ?? 0;
        const cta = !summary.votingOpen ? 'Vezi voturile' : total > 0 ? 'Votează' : 'Fii primul care votează';
        const label = [
            importance && (averageText ? `Importanță ${averageText}` : 'Importanță: niciun vot'),
            total > 0 && votesLabel(total),
            binary && `${binary.result.counts[1] ?? 0} ${positive}, ${binary.result.counts[0] ?? 0} ${negative}`,
            choice && `Sondaj, ${choice.result.total} ${choice.result.total === 1 ? 'participant' : 'participanți'}`,
            cta,
        ].filter(Boolean).join(' · ');
        return <ButtonBase onClick={onVote} aria-label={label} data-testid="subject-vote-header"
                           sx={{alignSelf: 'flex-start', maxWidth: '100%', justifyContent: 'flex-start', textAlign: 'left', borderRadius: 2,
                               px: 0.5, mx: -0.5, py: 0.25, '&:hover .vote-cta': {textDecoration: 'underline'},
                               '&.Mui-focusVisible': {outline: '2px solid', outlineColor: 'primary.main'}}}>
            <Stack component="span" direction="row" spacing={1.5} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center'}}>
                {importance && <Box component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.75}}>
                    <Rating readOnly value={average ?? 0} precision={0.1} icon={<StarRoundedIcon fontSize="inherit"/>} emptyIcon={<StarRoundedIcon fontSize="inherit"/>}
                            sx={{color: '#c58b12', '& .MuiRating-iconEmpty': {color: 'text.disabled'}}}/>
                    {averageText && <Typography component="span" variant="body2" sx={{fontWeight: 700}}>{averageText}</Typography>}
                </Box>}
                {total > 0 && <Typography component="span" variant="body2" color="text.secondary">{votesLabel(total)}</Typography>}
                {binary && <Box component="span" sx={{display: 'inline-flex', gap: 0.5, alignItems: 'center', typography: 'body2'}}>
                    <ThumbUpOutlinedIcon sx={{fontSize: 18}}/>{binary.result.counts[1] ?? 0}
                    <ThumbDownOutlinedIcon sx={{fontSize: 18, ml: 0.75}}/>{binary.result.counts[0] ?? 0}
                </Box>}
                {choice && <Typography component="span" variant="body2" color="text.secondary">
                    Sondaj · {choice.result.total} {choice.result.total === 1 ? 'participant' : 'participanți'}{summary.frozen ? ' · închis' : ''}
                </Typography>}
                <Typography className="vote-cta" component="span" variant="body2"
                            sx={{color: 'primary.main', fontWeight: 600, display: 'inline-flex', alignItems: 'center'}}>
                    {cta}<ArrowDownwardIcon sx={{fontSize: 16, ml: 0.25}}/>
                </Typography>
            </Stack>
        </ButtonBase>;
    }
    return <Stack component="span" direction="row" spacing={1.5} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center', fontSize: 12}}>
        {importance && <Tooltip title={average === null ? 'Importanță: niciun vot' : `Importanță: ${importance.result.total} voturi pe versiunea curentă`}>
            <Box component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5}}>
                <Rating readOnly size="small" value={average ?? 0} precision={0.1} icon={<StarRoundedIcon fontSize="inherit"/>} emptyIcon={<StarRoundedIcon fontSize="inherit"/>}
                    sx={{color: '#c58b12', '& .MuiRating-iconEmpty': {color: 'text.disabled'}}}/>
                <Typography component="span" variant="caption">{averageText ?? '—'}</Typography>
            </Box>
        </Tooltip>}
        {binary && <Tooltip title={binary.code === 'YES_NO' ? 'Susțin / Nu susțin pe versiunea curentă' : 'Validări / invalidări pe versiunea curentă'}>
            <Box component="span" sx={{display: 'inline-flex', gap: 0.5, alignItems: 'center'}} aria-label={`${binary.result.counts[1] ?? 0} ${positive}, ${binary.result.counts[0] ?? 0} ${negative}`}>
                <ThumbUpOutlinedIcon sx={{fontSize: 14}}/>{binary.result.counts[1] ?? 0}
                <ThumbDownOutlinedIcon sx={{fontSize: 14, ml: 0.5}}/>{binary.result.counts[0] ?? 0}
            </Box>
        </Tooltip>}
        {choice && <Typography component="span" variant="caption" color="text.secondary">
            Sondaj · {choice.result.total} {choice.result.total === 1 ? 'participant' : 'participanți'}{summary.frozen ? ' · închis' : ''}
        </Typography>}
    </Stack>;
}
