# Changelog

## 0.6.0 — refonte de l’interface admin (en cours, branche `ui/native-refonte-060`)

### Décisions Q1-Q6 (2026-11-25) : taxonomie Google non embarquée

- **Licence vérifiée** : Google publie `taxonomy-with-ids.<langue>.txt` (page d’aide Merchant Center « Google product category », answer 6324436) sans licence de redistribution ; le fichier ne porte qu’une ligne de version, et les Conditions d’utilisation de Google gardent ses droits sans accorder de licence pour ce fichier. Ce n’est pas clair : je ne l’embarque pas (consigne).
- **Retrait** (`data/google-taxonomy-en-US.txt` supprimé, `src/Admin/GoogleTaxonomy.php`, `src/Admin/Editor.php`, `assets/js/editor.js`) : la recherche locale du lot 3 reste dans le code mais ne s’active que si le site fournit sa propre copie par le filtre `oliprfe_google_taxonomy_file` (chemin absolu ; reçoit la locale, pour FR ou EN). Sans copie, les champs prennent un ID numérique comme en 0.5.7 (`inputmode="numeric"`, pas de `datalist`, aucune requête AJAX), avec un lien vers la liste sur l’aide de Google. Le readme l’explique (FAQ).
- **Compte des attributs vides par attribut** : fait au lot 4 (table « Empty values by attribute » de Diagnostics et rapport).

### Lot 4 (2026-11-25) : Diagnostics, Activity log, réglages

- **Diagnostics** (`src/Admin/Diagnostics.php`, nouveau) : l’onglet remplace le panneau d’attente. Sélecteur de flux + « Show » (formulaire GET) ; sans paramètre, le premier flux en erreur, sinon le dernier généré. Ligne de contexte (date, durée, poids), notice si la dernière génération a échoué. 4 tuiles : Products checked = Items in the feed + Excluded, Warnings. Table « Excluded products » : libellé traduit + code du moteur, nombre, « View », « How to fix » avec un lien vers la bonne section de l’éditeur (`#products`, `#mapping`) ; un code inconnu s’affiche brut (jamais vide). « View » liste dans l’onglet les produits gardés par le moteur (100 au plus) avec leur lien d’édition et « Showing the first 100. » quand il y en a plus. Table « Warnings » : marque vide (une seule ligne, avec ses produits), valeurs multiples, GTIN invalide, attributs requis ou recommandés vides. **Table « Empty values by attribute »** : le nombre de produits vides pour chaque attribut, avec son niveau (décision Q6). « Site checks » : Action Scheduler / WP-Cron (en retard ou non), dossier de sortie, dernier fichier du flux, TranslatePress, Price Based on Country ; chaque problème a une phrase et un lien. « Copy a diagnostic report » : texte local dans un `<textarea>` en lecture seule + « Copy report » ; versions, réglages utiles du flux, chiffres, contrôles ; aucune URL du site, aucun courriel, aucune clé ; rien n’est envoyé.
- **Activity log** (`src/Activity.php`, `src/Admin/ActivityTable.php`, nouveaux) : `WP_List_Table` Date / Feed / Event / By, filtres flux et événement, 20 par page. Les 10 événements sont journalisés : generated, failed, queued, cancelled, created, edited, activated, deactivated, deleted, settings saved. Auteur : l’usager, « Schedule » ou « Product update » (la vérification planifiée distingue un flux dû d’un flux relancé par une modification de produit ; l’auteur du départ suit la génération jusqu’à « generated » ou « failed »). Stockage : une option non chargée automatiquement (`oliprfe_activity`), triée, bornée par la rétention ; un flux supprimé garde son nom dans le journal. Au premier affichage de l’onglet, les exécutions gardées par la 0.5.7 dans chaque flux (`_oliprfe_log`, lecture seule) sont importées une fois avec leur horodatage d’origine (drapeau `oliprfe_activity_imported`, sans doublon). Les erreurs vont toujours au journal WooCommerce.
- **Réglage `log_keep`** (`src/Settings.php`) : seul nouveau réglage, 200 par défaut (10 à 5 000), fusionné avec les défauts : `oliprfe_settings` n’est pas réécrit tant qu’on n’enregistre pas les réglages. Baisser la valeur purge tout de suite les entrées en trop. La désinstallation avec « Plugin data » supprime le journal (préfixe `oliprfe_` déjà couvert).
- **Settings** (`src/Admin/Admin.php`) : sections Feed files, Generation (Product changes, Schedule check en lecture seule), Access, **Activity log** (Keep N entries + niveau du journal WooCommerce, déplacé depuis Generation), Uninstall (« Plugin data » toujours décoché par défaut ; la description précise que désactiver ne supprime rien) ; un seul « Save Changes ». Maintenance garde son formulaire et son nonce, bouton « Generate all active feeds ». Le formulaire reste celui de la 0.5.7 (`admin-post.php`, `Settings::save()`), parce que l’enregistrement déplace les fichiers quand le dossier change et refuse un dossier inutilisable ; je ne l’ai pas réécrit avec `register_setting()`.
- **Éditeur** (`src/Admin/Editor.php`) : la boîte « Last runs » est retirée (P3-8) ; une boîte « Runs » donne « Diagnostics of the last generation » et « View all runs » (journal filtré sur le flux).
- **Moteur** (`src/Generator.php`, `src/Scheduler.php`, `src/Feeds.php`) : l’entrée de journal garde aussi `warned` (total par avertissement ; les ID restent limités à 50). `Scheduler::use_as()` devient public pour les contrôles du site.
- **Correction du lot 3** (`src/Admin/Admin.php`) : l’aperçu AJAX passait une configuration sans `name` au générateur (avertissement PHP « Undefined array key name ») ; le nom du formulaire est maintenant transmis.
- **CSS** : aucune règle ajoutée (6 123 octets) ; les tables de l’onglet réutilisent `widefat striped` et la classe défilante `.oliprfe-dry`.

### Lot 3 (2026-11-25) : correspondance (aperçu, Check) et Preview

- **Table de correspondance** (`src/Admin/Editor.php`) : 4 colonnes Attribute / Source / Preview / Check, groupes Required / Recommended / Custom. Custom regroupe les autres attributs du canal (l’ancien groupe Optional), puis ceux ajoutés à la main. La Source réunit le type, la valeur et les Options (`<details>`) dans la même cellule. Chaque ligne a une ancre `#oliprfe-map-{attribut}`. Aucun nom de champ `feed[…]` ne change.
- **Options** (`src/Admin/Editor.php`, `assets/js/editor.js`) : le `<details>` s’ouvre seulement si une option est remplie, et il est masqué quand la source est « Automatic ». Avant, on pouvait y remplir des options que l’enregistrement jetait sans rien dire (comportement de la 0.5.7).
- **Aperçu par produit** (`src/Admin/Editor.php`, `src/Admin/Admin.php`, `assets/js/editor.js`) : « Preview with product » propose les 20 premiers produits du flux, plus une recherche (AJAX `oliprfe_product_search`, nonce + capacité, 20 résultats au plus). Changer de produit remplace l’aperçu de toutes les lignes avec le formulaire non enregistré (AJAX `oliprfe_preview_row`) ; sur le banc, environ 0,2 s pour 33 attributs. Si le produit est exclu, la raison est annoncée.
- **Colonne Check** (`src/Generator.php`, `src/Admin/Editor.php`) : à chaque génération, j’enregistre dans l’entrée du journal (`_oliprfe_last_run` / `_oliprfe_log`) une nouvelle clé `empty` : par attribut, le nombre de produits où il est vide, plus les produits exclus parce qu’un attribut requis manquait (Q6). La colonne affiche Automatic, OK, Not sent, « Empty for N » (requis : erreur, recommandé : avertissement, le nombre de produits en infobulle) ou « Generate to check » s’il n’y a pas encore de génération avec ce compte. Les attributs vides par nature pour une partie des produits (`item_group_id`, `sale_price`, dates, délais, `identifier_exists`) restent neutres. Une ligne à problème a un filet à gauche **et** le texte. En haut de l’éditeur, une notice « N attributes need attention. » donne un lien par ligne ; le lien ouvre Mapping et met le focus sur la ligne. La case « Show only problems (N) » filtre la table. La configuration (`_oliprfe_config`) ne change pas.
- **Preview** (`src/Generator.php`, `src/Admin/Editor.php`, `src/Admin/ListTable.php`) : `Generator::dry_run()` construit à blanc les 5 premiers articles du flux (50 produits essayés au plus), sans verrou, sans fichier temporaire et sans écriture. Le panneau `#preview` montre une table (id, title, price, availability, link, Warnings), les produits sautés avec leur raison et le bloc brut au format du flux (`pre.oliprfe-raw`). On l’ouvre par l’action de ligne Preview ou le bouton Preview de la barre latérale : `?preview=1#preview` côté serveur avec la configuration enregistrée, sans JS ; avec JS, AJAX `oliprfe_preview` avec le formulaire non enregistré.
- **Catégorie Google** (`src/Admin/GoogleTaxonomy.php`, `data/google-taxonomy-en-US.txt`) : j’ai ajouté la liste officielle de la taxonomie Google (version 2021-09-21, 5 595 catégories, 483 ko). On tape des mots (« guitar ») ou un début d’ID dans le champ : les suggestions viennent de cette liste locale (AJAX `oliprfe_gcat_search`, 20 au plus, aucun appel réseau), dans un `<datalist>` natif. L’ID est enregistré et le chemin s’affiche sous le champ (lié par `aria-describedby`). Pareil pour la catégorie par défaut et pour chaque catégorie de produits.
- **Petites corrections de la revue (P3)** : le dialogue a une description (`aria-describedby`) ; « Copy » est masqué sans JS (`.no-js`) ; `.toggle-row` fait 44 × 44 px sous 782 px ; la notice « Feed created (inactive)… » est annoncée à l’ouverture de l’éditeur ; la barre latérale reprend la phrase d’erreur et « See diagnostics » de la liste.
- **Mobile** : les tables des catégories et de l’aperçu défilent dans leur cadre sous 782 px (plus de défilement horizontal de la page).
- **Tailles** : CSS 6 123 octets (budget 6 144), `editor.js` 15 314 octets, `admin.js` 10 678 octets.

### Repasse P2 des lots 1 et 2 (2026-11-25, revue du Designer)

- **Vues** (`src/Admin/ListTable.php`) : la vue Active compte maintenant tous les flux activés (publiés), comme la tuile « N of M » ; Errors reste un sous-ensemble et peut chevaucher Active ou Inactive, comme les vues de WordPress (P2-1).
- **Dialogue** (`assets/css/admin.css`) : le bouton « Delete N feeds » est enfin rouge (`#b32d2e`) : la règle `.wp-core-ui .button.oliprfe-danger` (+ `:hover`, `:focus`) l’emporte sur le bleu de WordPress (P2-2).
- **Erreurs de champ** (`src/Admin/Editor.php`) : `row()` prend l’erreur du champ et l’écrit dans sa cellule, après la description ; avant, le `<p>` d’erreur tombait entre deux `<tr>` et le navigateur le sortait de la table, loin du champ (File name, Channel) (P2-3).
- **Annonces** (`src/Admin/Admin.php`, `assets/js/admin.js`) : j’ai retiré `aria-live` du conteneur des notices de génération ; seul `wp.a11y.speak()` parle, une fois au départ, à 25, 50 et 75 %, et une seule fois à la fin (P2-4).
- **Don** (`src/Admin/Admin.php`, `readme.txt`) : le lien Donate reste seulement dans la méta de la ligne de l’extension (retiré des liens d’action) ; la section External services du readme ne parle plus de notice ni de pied de page.
- **Étapes** (`assets/css/admin.css`) : espace insécable après le numéro (« 2. Products » ne se colle plus sous 782 px) (P3-1).

### Lot 2 (2026-11-25) : éditeur en 5 sections, assistant léger

- **Éditeur en 5 sections** (`src/Admin/Editor.php`) : j’ai regroupé les 7 boîtes en 5 sections numérotées, une `postbox` chacune avec un `h2` focalisable : 1 Channel and target, 2 Products (filtres + titres et descriptions), 3 Mapping, 4 Categories and links (catégories + liens et images), 5 Schedule (avec la taille des lots). Les anciennes boîtes deviennent des sous-parties (`h3`) ; leurs identifiants et tous les champs `feed[…]` sont gardés tels quels (63 noms, comparés un par un avec la 0.5.7).
- **Navigation** (`src/Admin/Editor.php`, `assets/js/editor.js`) : sous le titre, `nav` « Feed sections » avec `ol.oliprfe-steps` (de vrais liens d’ancre) et, en bas de chaque section, des boutons « ← précédent / suivant → ». Sans JavaScript, les 5 sections restent empilées et l’enregistrement marche comme avant. Avec JavaScript, une seule section est visible, `aria-current="step"` suit la section, l’URL porte `#section`, recharger rouvre la même section et le focus va au titre de la section. Comme il n’y a qu’un formulaire, changer de section ne perd aucune saisie.
- **Retour sur la même section** (`src/Admin/Admin.php`) : un champ caché `oliprfe_section` (rempli par le JS) fait revenir l’enregistrement sur la section en cours (`#schedule`, etc.) ; la valeur est vérifiée contre la liste des sections.
- **Erreurs** (`assets/js/editor.js`, `assets/js/admin.js`) : une erreur de validation ouvre la section qui contient le premier champ fautif et donne le focus au résumé d’erreurs ; un lien du résumé ouvre sa section puis focalise le champ. Le focus du résumé est maintenant posé au `load`, après que WordPress a déplacé les notices (avant, ce déplacement lui faisait perdre le focus).
- **Barre latérale** (`src/Admin/Editor.php`) : Active, Status, Items (+ « N excluded » vers Diagnostics), Last, Next (« manual only » ou « not scheduled (inactive) »), URL courte + Copy, Delete feed, « Save feed » (seul bouton primaire) et « Save and generate now » en bouton-lien. Le « Save feed » du bas (petits écrans) n’est plus primaire, et l’éditeur n’affiche plus les onglets (comme la maquette 02).
- **Assistant léger** (`src/Admin/Editor.php`, `src/Admin/Admin.php`) : « Add New Feed » ouvre maintenant un seul écran (`div.oliprfe-panel`, maquette 05) : canal (avec ses formats), langue (sélecteur TranslatePress avec la langue par défaut, sinon « Site language (…) »), pays requis, devise (celle de la boutique par défaut) et nom suggéré « {Channel} — {Language} ({Country}) », mis à jour tant que le nom n’a pas été modifié à la main. « Create feed » passe par la nouvelle action `oliprfe_create_feed` (nonce + capacité) : le flux est créé **inactif** avec les défauts du canal (premier format du canal), un nom de fichier tiré du nom (unique), puis l’éditeur s’ouvre sur `#mapping` avec « Feed created (inactive). Review the mapping, then generate it. ». Sans pays, rien n’est créé : résumé d’erreurs, erreur liée au champ (`aria-invalid`, `aria-describedby`) et saisie gardée.
- **JS** (`assets/js/editor.js`) : j’ai sorti le code propre à l’éditeur (correspondance, horaire, formats du canal, commande en attente, décompte des produits) dans `editor.js`, chargé seulement sur `action=new|edit` de l’écran de l’extension : `admin.js` passe de 15 526 à 10 273 octets, `editor.js` fait 9 881 octets (cible : 16 Ko par fichier).
- **CSS** (`assets/css/admin.css`) : sections masquées, sous-titres, navigation bas de section, lien « Save and generate now », table des règles défilante sous 782 px et cibles de 44 px pour les liens de section : 5 907 octets (budget 6 144). J’ai retiré les règles de l’ancien champ d’URL de la barre latérale.
- **Données** : aucune clé ajoutée ni modifiée ; ouvrir puis enregistrer chaque flux sans rien changer laisse `_oliprfe_config` identique à l’octet près (CA-52).

### Lot 1 (2026-11-25) : structure, liste des flux, dialogue, retrait du don

- **Onglets** (`src/Admin/Admin.php`) : j’ai mis 4 onglets qui sont de vrais liens, Feeds / Diagnostics / Activity log / Settings, dans une `nav` nommée « Product feed screens » (au lieu de « Secondary menu »), `aria-current="page"` sur l’onglet actif. Le même titre « Product Feeds » et « Add New Feed » (`page-title-action`) sur chaque onglet. Diagnostics et Activity log affichent pour l’instant un panneau d’attente : leur contenu vient au lot 4.
- **Tuiles** (`src/Admin/Overview.php`) : j’ai ajouté 4 tuiles en haut de Feeds, calculées sur les dernières générations enregistrées (aucune boucle sur le catalogue, aucun appel réseau) : flux actifs (« N of M »), articles dans les flux, produits exclus (lien « Why? » vers Diagnostics) et flux en erreur (lien « Fix », seulement s’il y en a au moins un).
- **Notices** (`src/Admin/Overview.php`) : notice d’erreur « N feed(s) could not be generated. » avec le premier flux en erreur, son message et « See diagnostics » ; notice « Generating » par génération en cours, avec la progression et « Cancel generation ».
- **Liste** (`src/Admin/ListTable.php`) : j’ai réduit la table de 9 à 6 colonnes : Name (canal · format), Target (`langue · pays · devise`), Items (+ « N excluded » vers Diagnostics), Status, Last generated (+ prochaine exécution, « Running now », « Last success » ou « Manual only »), Feed URL (nom du fichier + Copy, nom accessible « Copy feed URL of {fichier} »). Colonnes masquables et nombre de flux par page dans Options de l’écran (`oliprfe_feeds_per_page`, défaut 20), tri par nom et par date, pagination.
- **Vues et filtres** (`src/Admin/ListTable.php`) : vues All / Active / Errors / Inactive avec comptes exacts (Active = publié et sans erreur, Errors = dernière génération en échec, Inactive = non publié), filtre par canal dans la barre d’actions, recherche par nom. L’ancien lien `?channel=errors` mène toujours à la vue Errors.
- **Statuts** (`src/Admin/Admin.php`) : 5 états, chacun icône décorative + texte (`oliprfe-is-ok|error|off`) : Active, Generating (`<progress>` + « N of M products »), Error (phrase + « See diagnostics »), Inactive, Never generated (« Generate the feed to publish its URL. » dans la colonne URL).
- **Actions** (`src/Admin/ListTable.php`, `src/Admin/Admin.php`) : actions de ligne Edit, Generate now (désactivé avec `aria-disabled` et une explication pendant une génération), Preview (ouvre l’éditeur ; l’aperçu à blanc vient au lot 3), Duplicate, Delete. Actions groupées Generate now, Activate, Deactivate, Delete, avec nonce `bulk-feeds` et capacité. Activer / désactiver ne change que le statut de l’article : la configuration n’est pas réécrite. Nouvelles actions `oliprfe_activate_feed`, `oliprfe_deactivate_feed` et `oliprfe_cancel_generation` (nonce par flux + capacité).
- **Suppression** (`src/Admin/Admin.php`, `assets/js/admin.js`, `src/Admin/Editor.php`) : j’ai remplacé `confirm()` par un `<dialog>` modal (ligne, actions groupées et éditeur) : titre au pluriel traduit (`_n()`), liste des flux et de leurs fichiers, conséquence pour les plateformes, « Deactivate instead », focus initial sur Cancel, Échap ferme, focus rendu au déclencheur. Sans JavaScript, le lien nonce garde son comportement.
- **Progression** (`assets/js/admin.js`, `src/Admin/Admin.php`) : la ligne « Generating » est rafraîchie toutes les 3 s par l’AJAX `oliprfe_feed_status` sans recharger la page ; à la fin, les cellules de la ligne sont remplacées par le HTML échappé du serveur, une notice en ligne (succès avec « See why N were excluded », ou erreur) est ajoutée et le résultat est annoncé par `wp.a11y.speak()` ; annonces à 25, 50 et 75 %.
- **Don retiré des écrans** (`src/Admin/Admin.php`, `src/Admin/Editor.php`) : j’ai retiré `donate_notice()`, `ajax_dismiss_donate()`, `support_footer()`, `donate_dismiss_js()` et le `wp_add_inline_script`. Le lien de don reste dans la ligne de l’extension et dans le readme. À la première visite des écrans 0.6, la méta utilisateur `oliprfe_donate_dismissed` devenue inutile est supprimée et l’option `oliprfe_ui_version` = 0.6.0 est posée (constante `OLIPRFE_UI_VERSION`).
- **CSS** (`assets/css/admin.css`) : j’ai repris la feuille de référence du Designer et gardé seulement les règles encore utiles à l’éditeur actuel : 5 789 octets (avant : 7 207 ; budget : 6 144). Cibles ≥ 44 px sous 782 px (boutons, actions de ligne, vues, onglets, boutons du dialogue), focus visible 2 px, aucune animation avec `prefers-reduced-motion`.
- **Correctif** (`src/Admin/Admin.php`) : l’onglet Settings plantait sur les PHP sans `GLOB_BRACE` (musl) ; je compte les fichiers extension par extension.
- **Tests** (`tests/migration/snapshot.php`) : instantané de non-perte en lecture seule (octets bruts de `_oliprfe_config` et de `oliprfe_settings`, SHA-256 des fichiers sans horodatages) pour CA-51 à CA-53.
- **Données** : CPT `oliprfe_feed`, `_oliprfe_config`, `oliprfe_settings` et URL des flux inchangés.
- **Version** (`oli-product-feed.php`, `readme.txt`) : 0.5.7 → 0.6.0 (`.pot` / `.po` mis à jour au lot 5).

## 0.5.7 (2026-10-21)

- **Emballage** (`.distignore`, `.gitattributes`) : le ZIP distribuable (`git archive` / `wp dist-archive`) exclut `tests/`, `bin/`, `scripts/`, `.github/`, `phpcs.xml*`, `phpunit.xml*`, `composer.json` / `composer.lock`, `package*.json`, `node_modules/`, `.gitignore`, `.distignore`, `.gitattributes` et les `*.md` internes (`readme.txt` conservé).
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/*.pot` / `*.po`) : 0.5.6 → 0.5.7.

## 2026-10-21

- **readme.txt** : section `== External services ==` (PayPal donate au clic, liens auteur/GitHub au clic, plateformes shopping qui récupèrent le fichier une fois l’URL soumise). Liens conditions + confidentialité. Section Privacy raccourcie.
- **Préfixe** (`assets/css/admin.css`, `assets/js/admin.js`, `src/Admin/*`) : identifiants admin `opf-` (3 caractères) → `oliprfe-` (classes, ids, `data-*`).
- **Migration** (`src/PrefixMigrate.php`) : à la mise à jour, copie des options, transients, type de contenu et meta `oli_pf_*` (≤ 0.3.4) vers `oliprfe_*`, puis suppression des anciennes clés, sans écraser une valeur déjà migrée.
- **uninstall.php** : nettoyage des restes `oli_pf_*` quand « Delete all data » est coché.
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/*.pot` / `*.po`) : 0.5.5 → 0.5.6.

## 2026-10-20

- **Liens** (`src/Util.php`) : les valeurs des paramètres ajoutés aux liens sont passées à `add_query_arg` après `rawurlencode`, pour qu’un `&`, un `#` ou un espace restent une valeur unique et valide (WordPress n’encode pas les valeurs nouvellement ajoutées).
- **Docs** (`src/Util.php`, `readme.txt`) : les clés des paramètres passent en minuscules (`sanitize_key`).
- **readme.txt** : entrée 0.5.3 ajoutée dans Upgrade Notice.
- **Tests** (`tests/test-link-args.php`) : `&`, `#` et espace dans une valeur ; le stub `add_query_arg` aligne le comportement de WordPress (valeurs nouvelles non encodées).
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/*.pot` / `*.po`) : 0.5.4 → 0.5.5.

- **Liens GitHub** (`oli-product-feed.php`, `languages/oli-product-feed.pot`, `languages/oli-product-feed-fr_FR.po`, `languages/oli-product-feed-fr_CA.po`, `.mo` recompilés) : compte GitHub `bigrat95` → `olivierbigras` (compte renommé ; redirection GitHub en place). `Contributors: bigrat95` (wp.org) inchangé. Aucun changement fonctionnel.
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/*.pot` / `*.po`) : 0.5.3 → 0.5.4.

## 2026-10-18

- **Traductions** (`languages/oli-product-feed.pot`, `languages/oli-product-feed-fr_FR.po`, `languages/oli-product-feed-fr_FR.mo`, `languages/oli-product-feed-fr_CA.po`, `languages/oli-product-feed-fr_CA.mo`) : toutes les chaînes de l’interface en français (liste des flux, éditeur, canaux, notices, CLI, métabox produit). Catalogue `fr_CA` en français québécois (courriel, etc.). Repli `fr_CA` → `fr_FR` via le filtre `load_textdomain_mofile` si le fichier canadien est absent (WordPress ne le fait pas tout seul ; pas de `load_plugin_textdomain()`, retiré pour la revue wp.org).
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/oli-product-feed.pot`) : 0.5.2 → 0.5.3.

- **Build** (`bin/build-zip.sh`) : script reproductible pour le ZIP installable (racine `oli-product-feed/`, sans tests / .git / node_modules / outils, fichier `.mo` FR compilé).
- **Traductions** (`languages/oli-product-feed-fr_FR.po`, `languages/oli-product-feed-fr_FR.mo`) : traductions FR des réglages Prix minimum et Paramètres ajoutés aux liens.
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/oli-product-feed.pot`) : 0.5.1 → 0.5.2.

- **Liens** (`src/Util.php`, `src/Feeds.php`, `src/ItemBuilder.php`, `src/Admin/Editor.php`) : réglage « Paramètres ajoutés aux liens » (chaîne de requête, ex. `wcpbc-manual-country=US`, vide par défaut). Ajouté à `<link>` (`g:link`) de chaque article et à `mobile_link` s’il existe, via `add_query_arg`, en conservant les paramètres déjà présents (`attribute_*` des variations, `lang`). Pas ajouté à `image_link`. Saisie limitée aux paires `clé=valeur` (`sanitize_key` / `sanitize_text_field`). Filtre `oliprfe_link_args` (tableau, produit, flux — ordre déjà utilisé par Price Based on Country). Sans réglage, la sortie reste identique.
- **Tests** (`tests/test-link-args.php`) : produit simple, variation `attribute_*`, paramètre déjà présent, `mobile_link`, `image_link` intact, filtre.
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/oli-product-feed.pot`) : 0.5.0 → 0.5.1.

- **Filtres** (`src/Feeds.php`, `src/Pricing.php`, `src/ItemBuilder.php`, `src/Admin/Editor.php`) : seuil « Prix minimum » par flux (décimal, vide ou 0 = désactivé). Les produits simples et les variations dont le prix affiché — prix soldé si une promo est active, sinon le prix régulier, dans la devise du flux, le même montant que `<g:price>` / `<g:sale_price>` (y compris Price Based on Country / prix manuels US) — est sous ce seuil sont exclus. La comparaison se fait sur ce prix final, sans convertir le seuil (150 USD pour un flux USD, 150 CAD pour un flux CAD). Filtre `oliprfe_min_price` (valeur, flux, produit) pour les surcharges. Sans seuil, la sortie des flux existants reste identique.
- **Tests** (`tests/test-min-price.php`) : cas vide/0, promo, devise du flux sans conversion, prix style WCPBC, filtre de surcharge.
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/oli-product-feed.pot`) : 0.4.1 → 0.5.0.

## 0.4.1 (2026-10-16)

- Fix: with TranslatePress, a `fr_CA` feed wrote `http://site/fr/fr/produit/slug/` (404) for every item link and the channel `<link>`. After `trp_switch_language()`, `get_permalink()` and `home_url()` already include `/fr/`; `Lang::url()` ran the converter again (second `/fr/`, `https` dropped) and `prefix_url()` compared against the already-prefixed `home_url()`. URL translation is now idempotent (existing language slug is not doubled), the base URL is taken in the default language then converted once, and `force_https` runs after conversion.

## 0.4.0 (2026-10-16)

- Backorder availability date: per-feed toggle and lead time in days (default 21) for products with stock status `onbackorder` or `outofstock` (when the feed includes them). Google, Microsoft (Bing) and Meta receive `g:availability` = `backorder` (or `preorder` if configured), `g:availability_date` in ISO 8601 with the site time zone (recalculated at each generation), and optional `min_handling_time` / `max_handling_time`. In-stock items are unchanged. Other channels are not affected.
- Optional per-product override meta (`_oliprfe_availability`: a number of days or a `YYYY-MM-DD` date) on the product Inventory tab and on each variation.
- Fix: TranslatePress language (e.g. `fr_CA`) was not applied during generation (cron, Action Scheduler, WP-CLI, admin). The plugin now calls `trp_switch_language()`, sets the WordPress locale, uses translated permalinks / `get_url_for_language()`, and falls back to the dictionary and the `/fr/` URL slug so titles, descriptions and links match the feed language.

## 0.3.9 (2026-10-14)

Published on WordPress.org 2026-10-16 (SVN r3750329): https://wordpress.org/plugins/oli-product-feed/

WordPress.org plugin review follow-up (Review ID P0TDX382228HGN).

- Removed the optional must-use loader (`mu-plugin/`): no file outside the plugin is loaded.
- Support footer: inline `style` attribute replaced by the `oliprfe-support-footer` class in `assets/css/admin.css`.
- Admin asset handles renamed `oli-pf-admin` -> `oliprfe-admin`, script object `oliPfAdmin` -> `oliprfeAdmin`.

## 0.3.8 (2026-10-14)

WordPress.org plugin review fix (Review ID P0TDX382228HGN).

- Support notice: the dismiss handler is attached to the enqueued `oli-pf-admin` script with `wp_add_inline_script()`; the inline `<script>` tag in `src/Admin/Admin.php` is removed.

## 0.3.7 (2026-10-12)

Donate / support links (same pattern as Oli Media Cleaner).

- Plugins screen: Feeds + Donate action links, Donate in plugin meta row
- Dismissible support notice on plugin admin screens
- Footer donation line on feeds list, settings and feed editor
- `Donate link` in `readme.txt` for the WordPress.org plugin page (PayPal)

## 0.3.6 (2026-10-12)

WordPress.org plugin review fixes (second round).

- Namespace: renamed `OliProductFeed` to `Oliprfe` (and `Oliprfe\Admin`, `Oliprfe\Channels`, …). Plugin Check treated the old namespace as the too-short prefix `oli` (3 characters). `Oliprfe` matches the `oliprfe` / `OLIPRFE_` prefix already used on globals. The `oli-product-feed` text domain and slug are unchanged.
- Escaping: admin form fragments in `Editor` and the feed URL control in `Admin` are escaped late with `wp_kses` at every `echo` (allowed tags/attributes for form controls). The previous `phpcs:ignore WordPress.Security.EscapeOutput` annotations on those lines are removed.
- Must-use loader: when the plugin is active, requires the local `src/Endpoint.php` with a literal path under `WP_PLUGIN_DIR` (the WordPress.org slug folder). No variable `require` and no remote file access. Expects the standard folder name `oli-product-feed`.

## 0.3.5 (2026-10-09)

WordPress.org plugin review fixes.

- Prefix: renamed the plugin prefix from `oli_pf` / `OLI_PF` to `oliprfe` / `OLIPRFE` on every global symbol — the five `OLIPRFE_*` constants, the two global helper functions (`oliprfe_get_feed`, `oliprfe_get_feed_url`), the mu-plugin global variable, and the fired hooks (`oliprfe_channels`, `oliprfe_feed_generated`, `oliprfe_item`, `oliprfe_price`, `oliprfe_output_dir`, etc.). For consistency the option names, post type (`oliprfe_feed`), meta keys, transients, query var, scheduler hooks and admin-post/AJAX action names were renamed too. The `oli-product-feed` text domain and slug are unchanged. Plugin Check derives the accepted prefix from a single underscore-segment of four or more characters; `oli_pf` split into `oli` (too short) plus `oli_pf` and was not recognized, so all `oli_pf_*` globals were flagged. `oliprfe` is one segment of seven characters and is accepted. Note: because the option names and post type changed, feeds and settings stored under the old prefix are not carried over; reactivate the plugin and recreate feeds (acceptable for a plugin still in initial review).
- Sanitization: `Admin::handle_save()` now sanitizes the posted configuration (new `Admin::sanitize_config()`: every value through `Feeds::sanitize()`, the name through `sanitize_text_field()`, the active flag cast to boolean) before it is validated, saved, or stored in the error transient for redisplay. Previously the parsed configuration was stored in the transient before `Feeds::sanitize()` ran (review flag at `src/Admin/Admin.php:494`). Behavior is unchanged; the stored and saved data are identical, only sanitized earlier.
- Internationalization: removed the `load_plugin_textdomain()` call and its `init` hook (`src/Plugin.php`). WordPress 4.6+ loads the text domain of a directory-hosted plugin automatically, and WordPress.org serves the translations from its translation platform.
- Translations: removed the bundled compiled and source language files (`languages/oli-product-feed-fr_CA.po/.mo`, `oli-product-feed-fr_FR.po/.mo`); the `.pot` template is kept. Translations come from the WordPress.org translation platform.
- Optional must-use loader (`mu-plugin/oli-product-feed-early.php`): the plugin folder is read from the active plugins list instead of the hardcoded `wp-content/plugins/oli-product-feed/` path (review flag at `mu-plugin/oli-product-feed-early.php:15`), so a renamed plugin folder still works.

## 0.3.4 (2026-10-06)

- Output folder that cannot be used (QA E1): `OutputDirs::check()` tells whether a folder can be the output folder: no symbolic link on the way below the uploads directory, real path strictly inside the real path of the uploads directory (so an uploads directory that is itself a symbolic link still works), writable, or creatable in a writable folder. The settings form and `wp oli-feed settings output_dir` refuse a new folder that fails this check, or that cannot be created when the files are moved: the previous value is kept, the other settings are saved, and an error notice gives the reason (WP-CLI: error, exit code 1). When the folder comes from the `oliprfe_output_dir` filter, or the current folder becomes unusable, the effective folder stays the recorded folder that holds the feed files (`Storage::folder()`, fallback). The feeds keep being generated and served there (static URL and endpoint 200), nothing is moved or deleted, the registry `oliprfe_output_dirs` is kept, and a persistent error notice on the plugin screens names the folder, the reason and where the feeds are. Without such a folder, generation stops with that message, logged on the feed and in the WooCommerce log. `OutputDirs::sync()` also does nothing when its target folder is not usable (defense in depth). Folders created during a failed nested creation are removed when empty.
- File of another origin in the new folder (QA E2): a feed file is not moved over a file of the same name that the plugin did not write, and its copy in the previous folder is no longer deleted. The previous folder keeps its registry entry with that file, the feed is served from there (static URL of the previous folder, endpoint, URL shown in the list), the other file is never touched, and a warning notice plus a WooCommerce log entry explain what to do. The move happens on a later sync once the way is free. Generation never overwrites a file it did not write: it refuses to start when the feed's file (or its `.tmp`) exists in the output folder without having been written by the plugin, or is a symbolic link, and refuses to publish when such a file appeared during the generation. Deletion (trash, delete, uninstall with purge) only removes files recorded as written by the plugin. The current-folder sweep of 0.3.2 at uninstall is removed: the registry covers it, and before 0.3.3 the registry is created from the existing files first.
- Symbolic link (QA E3, since 0.3.2): `Storage::ensure_dir()` refuses an output folder that is a symbolic link or is inside one, or whose real path is outside the uploads directory, so neither `index.php` nor a feed file is written through a link.
- Settings: help text of the output folder updated; new notices and messages translated (fr_CA, fr_FR).

## 0.3.3 (2026-10-06)

- Output folder: the folders and files written by the plugin are recorded (option `oliprfe_output_dirs`: per folder, created by the plugin or not, index.php written by the plugin or not, feed files written). When the folder changes (setting, or `oliprfe_output_dir` filter: applied on the next settings save or generation), the files of the existing feeds move to the new folder (never over an existing file), the other files of the plugin and their `.tmp` are deleted from the previous folders, the plugin's index.php is deleted when nothing else is left, and a previous folder is removed when the plugin created it and it is empty (parent folders it created included). Safeguards: real path strictly inside the uploads directory (never the uploads directory or a parent), no symbolic link followed or deleted, a folder with a running generation is cleaned when it ends. On the first load after the update, the current folder is recorded with the files of the existing feeds (rules of 0.3.2: index.php recognized by its content, default folder counted as created by the plugin).
- Uninstall with "Delete all data": every recorded output folder is cleaned with the same rules (the index.php stays when files the plugin did not write are left, so the folder cannot be listed); the WooCommerce logs of the plugin are deleted: files `oli-product-feed-YYYY-MM-DD-<hash>.log` and their rotations in the WooCommerce log folder (its default folder when WooCommerce is inactive), and the rows of source `oli-product-feed` in the database log handler table. Unchecked (default): unchanged from 0.3.2, feeds, settings, files and logs are kept (documented under the setting and in the readme).
- Trash: also hooked to `transition_post_status` (any change to `trash`: `wp_update_post`, `wp_insert_post`, `wp post update`), so the file of a trashed feed is removed at once and the static URL stops serving it; permanent deletion (`before_delete_post`) unchanged. A status changed by a direct database query fires no hook: the endpoint still checks the feed status before serving.
- Settings: help texts of the output folder and of the uninstall option updated (fr_CA, fr_FR).

## 0.3.2 (2026-10-06)

- Mapping: `color` and `size` now have an automatic source on every channel (Google, Pinterest, Meta, TikTok, Microsoft, generic): the first of `pa_color`, `pa_colour`, `color`, `colour` (resp. `pa_size`, `size`) that the product has, variation value first, translated, one value only. An explicit mapping (including "Not sent") always wins. Attribute sources accept alternatives separated by `|`.
- Trash: a feed trashed (or deleted) outside the plugin has its file and temporary file removed, its generation canceled and the early index refreshed, so neither the endpoint nor the static URL serves it; a trashed feed cannot be generated. The early path also checks the feed status (one primary key lookup; entries now carry the feed ID) and ignores an index built by another plugin version (rebuilt on init), so a feed trashed before the update, or by a direct database change, is not served either; on the first load after the update, the files of feeds trashed by an older version are removed.
- Static file mode: `?oliprfe_feed=<slug>` answers 404 (it showed the home page).
- Uninstall: removes the Action Scheduler history of the plugin (`oliprfe_tick`, `oliprfe_run_batch` and the `oli-product-feed` group: actions, logs, group), skipped when the tables are absent; the files of trashed feeds are deleted too.
- Admin: URL label `?<slug>` (distinct per feed, full URL in the title) with WordPress serving; `position: relative` on the link (the screen reader text overflowed by 9 px at 1440 px); URL column 14% wide, the Copy button wraps under the label instead of truncating it.
- Docs: capability `manage_woocommerce` (or `manage_options` when shop manager access is off) in the readme and under the setting.

## 0.3.1 (2026-10-06)

- Fix (WordPress serving): a generated feed that is inactive (draft, e.g. imported with `active: false`) answered 404 "Feed not found." on `?oliprfe_feed=`, early path and init path alike, while its file existed (and was public in static file mode). Every feed with a generated file is now served and listed in the early index; "inactive" only stops the scheduled generation.

## 0.3.0 (2026-10-06)

- Channels: TikTok Catalog (CSV, TSV, XML), Microsoft Merchant Center (TXT, XML) and a generic CSV/TSV/XML channel with free columns and XML element names.
- Filters: product tags (include, exclude) and price range on the price shown; live count of the matching products in the editor.
- Editor: preview column in the attribute mapping, "Submit this feed" help per channel, time zone note, next generation; list search and Errors view.
- Mapping: source "copy of another feed attribute" (final value, after the brand detection and the field rules), e.g. custom_label_0 = brand; per-product values (`item_overrides`).
- Descriptions: when the content has no translation in the feed language, the translated meta description is used (call to action removed); the cleanup keeps ", .011" and, in French, the space before ; : ! ?
- Titles: the type rule is tested on the name in every language (same type in every feed of a product); optional words in brackets in type labels ("[Guitar] Strap").
- WordPress serving: the pre-generated file is sent while the plugins load (autoloaded index), with an optional must-use loader (`mu-plugin/oli-product-feed-early.php`); `application/xml`; max-age = min(setting, half the regeneration interval); weak ETags and If-None-Match first; cookies and Link headers removed.
- Settings: log level (WooCommerce logs), shop manager access, regeneration when products change (at most every 15 minutes), regenerate all active feeds, next schedule check.

## 0.2.0 (2026-10-06)

- Admin: WooCommerce > Product Feeds with a feed list (WP_List_Table, status, progress, copyable URL, bulk actions), a complete editor (target, filters, availability and exclusion rules, texts, attribute mapping, categories, links, schedule) and a settings tab.
- Channels: Pinterest Catalogs and Meta (Facebook, Instagram) catalogs.
- Title template per feed (`{brand} {name} {type} - {variation}`, type per category).
- MPN = SKU for all brands or only for a list of brands; a valid GTIN is always sent.
- Brand: priority brand list for products with several brand terms, else the brand that opens the name; "one value only" option for mapped attributes.
- Titles: type by name pattern (before the category table), brand spelling from the brand term, patterns removed from variation values (for example a SKU prefix).
- Descriptions: patterns removed (for example a meta description call to action), sentences with an email address removed.
- Field rules: fill an empty attribute (color, age_group, gender…) for some Google categories, with a fixed value or from words of the product name; rules can be kept inactive.
- Scheduler: `wp oli-feed run-due` and an admin warning when WP-Cron does not run.
- Optional serving through WordPress (`?oliprfe_feed=<file>`): short Cache-Control, X-Robots-Tag noindex, ETag, Last-Modified, 304.
- Translations: fr_FR and fr_CA (`tools/build-translations.py`); installable ZIP built with `tools/build-zip.sh`.
- uninstall.php (data deleted only when the option is checked).
- Fix: variations of a batch could get the ID 0 when several batches ran in the same request.

## 0.1.0 (2026-10-05)

- Google Merchant Center feeds (XML, CSV, TSV), one per language and currency.
- TranslatePress and Price Based on Country integrations.
- Batch generation with Action Scheduler, lock and atomic swap; WP-CLI.
