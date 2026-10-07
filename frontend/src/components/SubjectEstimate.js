import {Box, Typography} from '@mui/material';

/** Always pair the amount with its untruncated scope, including in lists. */
export default function SubjectEstimate({subject, compact = false}) {
    if (subject.type !== 'PROPOSAL' || subject.costEstimate == null || !subject.costCurrency || !subject.costEstimateScope?.trim()) return null;
    // Format exact decimal strings without converting them to floating point.
    const [integer, fraction = '00'] = String(subject.costEstimate).split('.');
    const amount = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (fraction === '00' ? '' : `,${fraction.padEnd(2, '0')}`);
    const scope = compact ? subject.costEstimateScope.trim().split(/\r?\n/)[0] : subject.costEstimateScope;
    return <Box sx={{p: 1.5, borderRadius: 1, bgcolor: 'action.hover', overflowWrap: 'anywhere'}}>
        <Typography component="div" variant="body2" sx={{fontWeight: 600}}>Estimarea autorului: {amount} {subject.costCurrency}</Typography>
        <Typography component="div" variant="body2" color="text.secondary" sx={{whiteSpace: 'pre-wrap'}}>{scope}</Typography>
    </Box>;
}
