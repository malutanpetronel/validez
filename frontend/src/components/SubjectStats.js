import {Stack, Typography} from '@mui/material';
import HowToVoteOutlinedIcon from '@mui/icons-material/HowToVoteOutlined';
import LinkOutlinedIcon from '@mui/icons-material/LinkOutlined';
import PhotoLibraryOutlinedIcon from '@mui/icons-material/PhotoLibraryOutlined';
import {countLabel} from '../subjects/labels';

/**
 * Rândul de indicatori al unui subiect, ca rândul de reacții de la Facebook: câte persoane au votat revizia curentă
 * (fără rezultat - la sondaje rezultatul se vede abia după vot), linkuri și fotografii publicate. Doar ce e peste 0.
 */
export default function SubjectStats({subject}) {
    const items = [
        [HowToVoteOutlinedIcon, subject.voterCount, 'persoană a votat', 'persoane au votat'],
        [LinkOutlinedIcon, subject.linkCount, 'link', 'linkuri'],
        [PhotoLibraryOutlinedIcon, subject.photoCount, 'fotografie', 'fotografii'],
    ].filter(([, n]) => n > 0);
    if (items.length === 0) return null;
    // Iconițele în culoarea principală (legătura cu „Vezi subiectul”), textul normal și mediu - vizibil, dar nu arată ca un link.
    return <Stack component="span" direction="row" useFlexGap sx={{flexWrap: 'wrap', columnGap: 1.5, rowGap: 0.5, color: 'text.primary'}}>
        {items.map(([Icon, n, one, many]) => <Stack key={many} component="span" direction="row" sx={{alignItems: 'center', gap: 0.5, minWidth: 0}}>
            <Icon aria-hidden="true" sx={{fontSize: 18, color: 'primary.main'}}/>
            <Typography component="span" variant="caption" sx={{fontWeight: 600}}>{countLabel(n, one, many)}</Typography>
        </Stack>)}
    </Stack>;
}
