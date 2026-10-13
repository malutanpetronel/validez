import {useEffect, useState} from 'react';
import {Box, Rating, Stack, Tooltip, Typography} from '@mui/material';
import StarRoundedIcon from '@mui/icons-material/StarRounded';
import ThumbUpOutlinedIcon from '@mui/icons-material/ThumbUpOutlined';
import ThumbDownOutlinedIcon from '@mui/icons-material/ThumbDownOutlined';
import {fetchVotes} from '../api/votes';

export default function TreeVoteSummary({subjectId}) {
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
    const positive = binary?.code === 'YES_NO' ? 'susțineri' : 'validări';
    const negative = binary?.code === 'YES_NO' ? 'voturi împotrivă' : 'invalidări';
    const average = importance?.result.total > 0
        ? Object.entries(importance.result.counts).reduce((sum, [v, n]) => sum + Number(v) * n, 0) / importance.result.total : null;
    return <Stack component="span" direction="row" spacing={1.5} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center', fontSize: 12}}>
        {importance && <Tooltip title={average === null ? 'Importanță: niciun vot' : `Importanță: ${importance.result.total} voturi pe versiunea curentă`}>
            <Box component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5}}>
                <Rating readOnly size="small" value={average ?? 0} precision={0.1} icon={<StarRoundedIcon fontSize="inherit"/>} emptyIcon={<StarRoundedIcon fontSize="inherit"/>}
                    sx={{color: '#c58b12', '& .MuiRating-iconEmpty': {color: 'text.disabled'}}}/>
                <Typography component="span" variant="caption">{average === null ? '—' : `${average.toLocaleString('ro-RO', {maximumFractionDigits: 1})}/5`}</Typography>
            </Box>
        </Tooltip>}
        {binary && <Tooltip title={binary.code === 'YES_NO' ? 'Susțin / Nu susțin pe versiunea curentă' : 'Validări / invalidări pe versiunea curentă'}>
            <Box component="span" sx={{display: 'inline-flex', gap: 0.5, alignItems: 'center'}} aria-label={`${binary.result.counts[1] ?? 0} ${positive}, ${binary.result.counts[0] ?? 0} ${negative}`}>
                <ThumbUpOutlinedIcon sx={{fontSize: 14}}/>{binary.result.counts[1] ?? 0}
                <ThumbDownOutlinedIcon sx={{fontSize: 14, ml: 0.5}}/>{binary.result.counts[0] ?? 0}
            </Box>
        </Tooltip>}
    </Stack>;
}
