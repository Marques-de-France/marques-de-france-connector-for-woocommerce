import ast
from pathlib import Path

path = Path('marques-de-france-connector-for-woocommerce-fr_FR.po')
lines = path.read_text(encoding='utf-8').splitlines()

translations = {
    'Dashboard': 'Tableau de bord',
    'Product Feed': 'Flux de produits',
    'Sales': 'Ventes',
    'Settings': 'Paramètres',
    'Failed to load data.': 'Échec du chargement des données.',
    'Loading…': 'Chargement…',
    'Activate your store': 'Activez votre boutique',
    'Store registered': 'Boutique enregistrée',
    'Enter your activation code': 'Saisissez votre code d’activation',
    'Enter my activation code': 'Saisir mon code d’activation',
    'Revenue': 'Chiffre d’affaires',
    'Hub connected': 'Hub connecté',
    '✗ Invalid token — please re-enter your Secure Token in Settings.': '✗ Code d’accès invalide — veuillez ressaisir votre code d’accès dans les Paramètres.',
    'Total Sales': 'Ventes totales',
    'All time': 'Depuis toujours',
    'Confirmed': 'Confirmé',
    'Total Revenue': 'Chiffre d’affaires total',
    'Confirmed only': 'Confirmés uniquement',
    'This Month': 'Ce mois-ci',
    'sales': 'ventes',
    'Unsynced': 'Non synchronisé',
    'pending Hub sync': 'en attente de synchronisation',
    'Sales over the last 12 months': 'Ventes sur les 12 derniers mois',
    'No data for the selected period.': 'Aucune donnée pour la période sélectionnée.',
    'Last 7 days': '7 derniers jours',
    'Last 28 days': '28 derniers jours',
    'Last 90 days': '90 derniers jours',
    'Last 12 months': '12 derniers mois',
    'Last year': 'L’année dernière',
    'This year': 'Cette année',
    'Failed to load sales.': 'Échec du chargement des ventes.',
    'Revenue over time': 'Chiffre d’affaires dans le temps',
    'Daily': 'Journalier',
    'Monthly': 'Mensuel',
    'Search order ID…': 'Rechercher un numéro de commande…',
    'All statuses': 'Tous les statuts',
    'Cancelled': 'Annulé',
    'Refunded': 'Remboursé',
    'Pending': 'En attente',
    'From': 'Du',
    'To': 'Au',
    'Reset': 'Réinitialiser',
    'Order': 'Commande',
    'Attribution': 'Attribution',
    'Amount': 'Montant',
    'Status': 'Statut',
    'Date': 'Date',
    'No sales found.': 'Aucune vente trouvée.',
    'Page': 'Page',
    'Previous': 'Précédent',
    'Next': 'Suivant',
    'Settings saved.': 'Paramètres enregistrés.',
    'Failed to save settings.': 'Échec de l’enregistrement des paramètres.',
    'Connection': 'Connexion',
    'Secure Token': 'Code d’accès sécurisé',
    'The token provided by Marques de France when your store was registered.': 'Le code d’accès fourni par Marques de France lors de l’enregistrement de votre boutique.',
    'Save settings': 'Enregistrer les paramètres',
    'Share this URL with Marques de France to enable your product feed in the directory.': 'Partagez cette URL avec Marques de France pour activer votre flux de produits dans l’annuaire.',
    'Copied!': 'Copié !',
    'Copy URL': 'Copier l’URL',
    'Preview': 'Aperçu',
    'Marques de France': 'Marques de France',
    'Product feed': 'Flux de produits',
    'Sales tracking': 'Suivi des ventes',
    'Marques de France requires WooCommerce to be installed and active.': 'Marques de France nécessite que WooCommerce soit installé et activé.',
    'Marques de France – Attribution': 'Marques de France – Attribution',
    'No attribution detected for this order.': 'Aucune attribution détectée pour cette commande.',
    'Source': 'Source',
    'UTM Source': 'UTM Source',
    'UTM Medium': 'UTM Medium',
    'UTM Campaign': 'UTM Campaign',
    'UTM Content': 'UTM Content',
    'UTM Term': 'UTM Term',
    'Landing Site': 'Site d’arrivée',
    'Referring Site': 'Site référent',
    'Ref Param': 'Paramètre ref',
}

out = []
i = 0
while i < len(lines):
    line = lines[i]
    if line.startswith('msgid '):
        msgid_parts = [line[6:].strip()]
        j = i + 1
        while j < len(lines) and lines[j].startswith('"'):
            msgid_parts.append(lines[j].strip())
            j += 1
        try:
            msgid_text = ast.literal_eval(''.join(msgid_parts))
        except Exception:
            msgid_text = ''.join(msgid_parts)

        out.append(line)
        out.extend(lines[i + 1:j])

        k = j
        while k < len(lines) and not lines[k].startswith('msgstr '):
            out.append(lines[k])
            k += 1

        if k < len(lines) and lines[k].startswith('msgstr '):
            if msgid_text in translations:
                trans = translations[msgid_text]
                escaped = trans.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')
                out.append('msgstr "' + escaped + '"')
                i = k + 1
                continue
            out.append(lines[k])

        i = k + 1
        continue

    out.append(line)
    i += 1

path.write_text('\n'.join(out) + '\n', encoding='utf-8')
print('Filled', path)
