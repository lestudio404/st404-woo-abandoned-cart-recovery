# Plugin Paniers Abandonnés - WooCommerce

Un plugin WordPress simple et efficace pour récupérer les paniers abandonnés avec envoi d'emails automatiques.

## 🚀 Fonctionnalités

### 📧 Système d'emails automatiques
- **Deux emails de rappel** configurables
- **Délais personnalisables** (minutes, heures, jours)
- **Éditeur WYSIWYG** pour le contenu des emails
- **Variables dynamiques** : nom client, articles, total, lien de récupération

### 👥 Gestion des utilisateurs
- **Exclusion de rôles** : choisissez quels rôles ne recevront pas d'emails
- **Support clients invités** et connectés
- **Suivi par email** pour les utilisateurs non connectés

### 📊 Interface d'administration moderne
- **Tableau de bord** avec statistiques en temps réel
- **Liste des paniers abandonnés** avec pagination
- **Actions rapides** : marquer comme récupéré, supprimer
- **Design responsive** et moderne

### ⚙️ Configuration complète
- **Nom et email de l'expéditeur** personnalisables
- **Délais d'envoi** flexibles pour chaque email
- **Contenu HTML** riche avec variables dynamiques
- **Sauvegarde AJAX** sans rechargement de page

## 📋 Prérequis

- WordPress 5.0 ou supérieur
- WooCommerce 5.0 ou supérieur
- PHP 7.4 ou supérieur

## 🔧 Installation

1. **Téléchargez** le plugin dans le dossier `/wp-content/plugins/` de votre WordPress
2. **Activez** le plugin depuis l'administration WordPress
3. **Configurez** les réglages dans "Paniers Abandonnés > Réglages"
4. **Testez** en ajoutant des produits au panier puis en abandonnant

## 🎯 Utilisation

### Configuration initiale

1. Allez dans **Paniers Abandonnés > Réglages**
2. Configurez l'**expéditeur** des emails
3. Sélectionnez les **rôles à exclure** des rappels
4. Personnalisez les **deux emails de rappel** :
   - Délai d'envoi
   - Objet de l'email
   - Contenu avec variables

### Variables disponibles

Dans le contenu des emails, vous pouvez utiliser ces variables :

- `{customer_name}` - Nom du client
- `{cart_items}` - Liste des articles du panier
- `{cart_total}` - Total du panier formaté
- `{cart_url}` - Lien pour récupérer le panier
- `{site_name}` - Nom du site

### Surveillance des paniers

1. Consultez **Paniers Abandonnés** pour voir tous les paniers
2. **Statistiques en temps réel** :
   - Total des paniers
   - Montant récupérable
   - Montant déjà récupéré
3. **Actions disponibles** :
   - Marquer comme récupéré
   - Supprimer un panier
   - Voir les emails envoyés

## 🔄 Fonctionnement technique

### Détection des paniers abandonnés
- **Hook WooCommerce** : `woocommerce_cart_updated`
- **Sauvegarde automatique** des paniers non vides
- **Support clients connectés** et invités

### Envoi des emails
- **Cron WordPress** toutes les heures
- **Vérification des délais** configurés
- **Exclusion des rôles** sélectionnés
- **Suivi des envois** dans la base de données

### Récupération des paniers
- **Hook WooCommerce** : `woocommerce_checkout_order_processed`
- **Marquage automatique** comme récupéré
- **Lien de récupération** dans les emails

## 📊 Base de données

Le plugin crée une table `wp_abandoned_carts` avec :

- `id` - Identifiant unique
- `user_id` - ID utilisateur (optionnel)
- `user_email` - Email du client
- `user_name` - Nom du client
- `cart_data` - Données du panier (JSON)
- `cart_total` - Total du panier
- `created_at` - Date de création
- `updated_at` - Date de mise à jour
- `first_email_sent` - Date premier email
- `second_email_sent` - Date deuxième email
- `recovered_at` - Date de récupération

## 🎨 Personnalisation

### Styles CSS
Le plugin utilise des styles CSS modernes avec :
- **Gradients** pour les cartes de statistiques
- **Animations** pour les interactions
- **Design responsive** pour tous les écrans
- **Couleurs cohérentes** avec WordPress

### JavaScript
- **AJAX** pour les interactions sans rechargement
- **Validation** côté client
- **Feedback visuel** pour les actions
- **Pagination** dynamique

## 🔒 Sécurité

- **Nonces WordPress** pour toutes les actions AJAX
- **Vérification des permissions** utilisateur
- **Sanitisation** des données d'entrée
- **Échappement** des données de sortie

## 🐛 Dépannage

### Les emails ne s'envoient pas
1. Vérifiez la **configuration SMTP** de WordPress
2. Testez avec un **plugin d'email** comme WP Mail SMTP
3. Vérifiez les **logs d'erreur** WordPress

### Les paniers ne sont pas détectés
1. Assurez-vous que **WooCommerce est actif**
2. Vérifiez que les **hooks WooCommerce** fonctionnent
3. Testez avec un **client connecté** et un **client invité**

### Problèmes d'affichage
1. Vérifiez la **compatibilité du thème**
2. Désactivez les **plugins de cache**
3. Testez avec un **thème par défaut**

## 📈 Statistiques et performances

### Optimisations incluses
- **Indexation** de la base de données
- **Pagination** pour les grandes listes
- **Requêtes optimisées** avec LIMIT/OFFSET
- **Cache des rôles** utilisateur

### Monitoring recommandé
- Surveillez la **taille de la table** `wp_abandoned_carts`
- Vérifiez les **logs du cron** WordPress
- Testez régulièrement l'**envoi d'emails**

## 🔄 Mises à jour

### Version 1.0.0
- ✅ Fonctionnalités de base
- ✅ Interface d'administration
- ✅ Système d'emails
- ✅ Configuration complète

### Prochaines versions prévues
- 📅 Rapports et analytics
- 📅 Templates d'emails prédéfinis
- 📅 Intégration avec d'autres plugins
- 📅 API REST pour développeurs

## 🤝 Support

Pour toute question ou problème :
1. Consultez cette documentation
2. Vérifiez les logs d'erreur WordPress
3. Testez avec un thème par défaut
4. Contactez le développeur

## 📄 Licence

Ce plugin est distribué sous licence GPL v2 ou ultérieure.

---

**Développé avec ❤️ pour WooCommerce**
