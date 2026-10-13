import {Link as RouterLink} from 'react-router-dom';
import {Breadcrumbs, Link, Typography} from '@mui/material';

/** items: [{name, slugPath}] de la rădăcină; currentIsLast: ultimul element e pagina curentă (text, nu link). */
export default function CategoryBreadcrumbs({items, currentIsLast = false}) {
    if (!items?.length) return null;
    return <Breadcrumbs aria-label="Categorii" sx={{'& ol': {flexWrap: 'wrap'}}}>
        {items.map((c, i) => (currentIsLast && i === items.length - 1
            ? <Typography key={c.slugPath} color="text.primary" aria-current="page">{c.name}</Typography>
            : <Link key={c.slugPath} component={RouterLink} to={`/categorie/${c.slugPath}`} underline="hover" color="inherit">{c.name}</Link>))}
    </Breadcrumbs>;
}
