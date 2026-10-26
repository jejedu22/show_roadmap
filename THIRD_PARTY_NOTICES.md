# Composants tiers

Show Roadmap est distribué sous licence [AGPL-3.0-or-later](LICENSE). Il s'appuie sur les composants libres ci-dessous,
qui restent sous leurs propres licences (tous compatibles avec l'AGPL). Liste générée à partir de `backend/composer.lock`
et `frontend/package-lock.json` (dépendances de production uniquement).

## Points d'attention

- **dompdf** (génération des PDF) et ses bibliothèques sont sous licence **LGPL** : utilisées telles quelles, sans modification. Leur code source est disponible auprès de leurs auteurs (https://github.com/dompdf).
- **Polices Inter et Bricolage Grotesque** (`@fontsource-variable/*`) : SIL **Open Font License 1.1** (copyright de leurs auteurs : The Inter Project Authors, The Bricolage Grotesque Project Authors). Elles sont embarquées dans l'interface ; le texte de la licence est fourni avec chaque paquet.
- **Horaires de train** : données ouvertes de la SNCF (Licence Ouverte / Open Licence), téléchargées au moment de l'exécution et non redistribuées avec le code.

## PHP (87 paquets)

| Paquet | Version | Licence |
|---|---|---|
| composer/pcre | 3.4.0 | MIT |
| doctrine/collections | 2.6.0 | MIT |
| doctrine/dbal | 4.5.0 | MIT |
| doctrine/deprecations | 1.1.6 | MIT |
| doctrine/doctrine-bundle | 2.19.2 | MIT |
| doctrine/doctrine-migrations-bundle | 3.7.1 | MIT |
| doctrine/event-manager | 2.1.1 | MIT |
| doctrine/inflector | 2.1.0 | MIT |
| doctrine/instantiator | 2.0.0 | MIT |
| doctrine/lexer | 3.0.3 | MIT |
| doctrine/migrations | 3.9.8 | MIT |
| doctrine/orm | 3.7.5 | MIT |
| doctrine/persistence | 4.2.1 | MIT |
| doctrine/sql-formatter | 1.5.4 | MIT |
| dompdf/dompdf | v3.1.6 | LGPL-2.1 |
| dompdf/php-font-lib | 1.0.2 | LGPL-2.1-or-later |
| dompdf/php-svg-lib | 1.0.2 | LGPL-3.0-or-later |
| egulias/email-validator | 4.0.4 | MIT |
| maennchen/zipstream-php | 3.2.2 | MIT |
| markbaker/complex | 3.0.2 | MIT |
| markbaker/matrix | 3.0.1 | MIT |
| masterminds/html5 | 2.12.0 | MIT |
| monolog/monolog | 3.12.1 | MIT |
| phpoffice/phpspreadsheet | 5.10.0 | MIT |
| psr/cache | 3.0.0 | MIT |
| psr/clock | 1.0.0 | MIT |
| psr/container | 2.0.2 | MIT |
| psr/event-dispatcher | 1.0.0 | MIT |
| psr/log | 3.0.2 | MIT |
| psr/simple-cache | 3.0.0 | MIT |
| sabberworm/php-css-parser | v9.5.0 | MIT |
| symfony/cache | v7.4.21 | MIT |
| symfony/cache-contracts | v3.7.1 | MIT |
| symfony/clock | v7.4.21 | MIT |
| symfony/config | v7.4.17 | MIT |
| symfony/console | v7.4.21 | MIT |
| symfony/dependency-injection | v7.4.21 | MIT |
| symfony/deprecation-contracts | v3.7.1 | MIT |
| symfony/doctrine-bridge | v7.4.21 | MIT |
| symfony/dotenv | v7.4.18 | MIT |
| symfony/error-handler | v7.4.20 | MIT |
| symfony/event-dispatcher | v7.4.17 | MIT |
| symfony/event-dispatcher-contracts | v3.7.1 | MIT |
| symfony/filesystem | v7.4.21 | MIT |
| symfony/finder | v7.4.20 | MIT |
| symfony/flex | v2.11.0 | MIT |
| symfony/framework-bundle | v7.4.21 | MIT |
| symfony/http-client | v7.4.21 | MIT |
| symfony/http-client-contracts | v3.7.3 | MIT |
| symfony/http-foundation | v7.4.21 | MIT |
| symfony/http-kernel | v7.4.21 | MIT |
| symfony/lock | v7.4.21 | MIT |
| symfony/mailer | v7.4.19 | MIT |
| symfony/mime | v7.4.19 | MIT |
| symfony/monolog-bridge | v7.4.18 | MIT |
| symfony/monolog-bundle | v4.1.0 | MIT |
| symfony/options-resolver | v7.4.8 | MIT |
| symfony/password-hasher | v7.4.8 | MIT |
| symfony/polyfill-intl-grapheme | v1.43.0 | MIT |
| symfony/polyfill-intl-idn | v1.43.0 | MIT |
| symfony/polyfill-intl-normalizer | v1.43.0 | MIT |
| symfony/polyfill-mbstring | v1.43.0 | MIT |
| symfony/polyfill-php83 | v1.43.0 | MIT |
| symfony/polyfill-php84 | v1.43.0 | MIT |
| symfony/polyfill-php85 | v1.43.0 | MIT |
| symfony/polyfill-php86 | v1.43.0 | MIT |
| symfony/property-access | v7.4.16 | MIT |
| symfony/property-info | v7.4.20 | MIT |
| symfony/rate-limiter | v7.4.18 | MIT |
| symfony/routing | v7.4.21 | MIT |
| symfony/runtime | v7.4.21 | MIT |
| symfony/security-bundle | v7.4.21 | MIT |
| symfony/security-core | v7.4.21 | MIT |
| symfony/security-csrf | v7.4.8 | MIT |
| symfony/security-http | v7.4.21 | MIT |
| symfony/service-contracts | v3.7.3 | MIT |
| symfony/stopwatch | v7.4.8 | MIT |
| symfony/string | v7.4.21 | MIT |
| symfony/translation-contracts | v3.7.1 | MIT |
| symfony/twig-bridge | v7.4.19 | MIT |
| symfony/twig-bundle | v7.4.21 | MIT |
| symfony/type-info | v7.4.20 | MIT |
| symfony/validator | v7.4.21 | MIT |
| symfony/var-dumper | v7.4.21 | MIT |
| symfony/var-exporter | v7.4.18 | MIT |
| symfony/yaml | v7.4.21 | MIT |
| twig/twig | v3.30.0 | BSD-3-Clause |

## JavaScript (70 paquets de production)

| Paquet | Version | Licence |
|---|---|---|
| @babel/helper-string-parser | 7.29.11 | MIT |
| @babel/helper-string-parser | 8.0.8 | MIT |
| @babel/helper-validator-identifier | 7.29.7 | MIT |
| @babel/helper-validator-identifier | 8.0.6 | MIT |
| @babel/parser | 7.29.11 | MIT |
| @babel/parser | 8.0.8 | MIT |
| @babel/types | 7.29.11 | MIT |
| @babel/types | 8.0.8 | MIT |
| @fontsource-variable/bricolage-grotesque | 5.3.0 | OFL-1.1 |
| @fontsource-variable/inter | 5.3.0 | OFL-1.1 |
| @jridgewell/gen-mapping | 0.3.13 | MIT |
| @jridgewell/remapping | 2.3.5 | MIT |
| @jridgewell/resolve-uri | 3.1.2 | MIT |
| @jridgewell/sourcemap-codec | 1.6.0 | MIT |
| @jridgewell/trace-mapping | 0.3.31 | MIT |
| @types/estree | 1.0.9 | MIT |
| @vue-macros/common | 3.1.4 | MIT |
| @vue/compiler-core | 3.5.44 | MIT |
| @vue/compiler-dom | 3.5.44 | MIT |
| @vue/compiler-sfc | 3.5.44 | MIT |
| @vue/compiler-ssr | 3.5.44 | MIT |
| @vue/devtools-api | 8.2.1 | MIT |
| @vue/devtools-kit | 8.2.1 | MIT |
| @vue/devtools-shared | 8.2.1 | MIT |
| @vue/reactivity | 3.5.44 | MIT |
| @vue/runtime-core | 3.5.44 | MIT |
| @vue/runtime-dom | 3.5.44 | MIT |
| @vue/server-renderer | 3.5.44 | MIT |
| @vue/shared | 3.5.44 | MIT |
| acorn | 8.19.0 | MIT |
| ast-kit | 2.2.0 | MIT |
| ast-kit | 3.0.0 | MIT |
| ast-walker-scope | 0.10.0 | MIT |
| birpc | 2.9.0 | MIT |
| chokidar | 5.0.0 | MIT |
| confbox | 0.1.8 | MIT |
| confbox | 0.3.1 | MIT |
| csstype | 3.2.3 | MIT |
| entities | 7.0.1 | BSD-2-Clause |
| estree-walker | 2.0.2 | MIT |
| estree-walker | 3.0.3 | MIT |
| exsolve | 1.1.3 | MIT |
| fdir | 6.5.0 | MIT |
| hookable | 5.5.3 | MIT |
| local-pkg | 1.2.1 | MIT |
| magic-string | 0.30.21 | MIT |
| magic-string | 1.4.4 | MIT |
| magic-string-ast | 1.0.3 | MIT |
| mlly | 1.8.2 | MIT |
| muggle-string | 0.4.1 | MIT |
| nanoid | 3.3.20 | MIT |
| nostics | 1.3.0 | MIT |
| pathe | 2.0.3 | MIT |
| perfect-debounce | 2.1.0 | MIT |
| picocolors | 1.1.1 | ISC |
| picomatch | 4.0.7 | MIT |
| pkg-types | 1.3.1 | MIT |
| pkg-types | 2.4.0 | MIT |
| postcss | 8.5.31 | MIT |
| quansync | 0.2.11 | MIT |
| readdirp | 5.1.1 | MIT |
| scule | 1.3.0 | MIT |
| source-map-js | 1.2.2 | BSD-3-Clause |
| tinyglobby | 0.2.17 | MIT |
| ufo | 1.6.4 | MIT |
| unplugin | 3.5.0 | MIT |
| unplugin-utils | 0.3.2 | MIT |
| vue | 3.5.44 | MIT |
| vue-router | 5.4.0 | MIT |
| webpack-virtual-modules | 0.6.2 | MIT |

Les outils de compilation (Vite…) servent uniquement à construire l'interface et ne sont pas distribués.
