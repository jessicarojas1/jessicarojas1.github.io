# CITADEL — Language & Format Coverage

CITADEL recognizes **117** languages and formats (102 code-bearing). Legend: **SAST** = language-specific static-analysis rules · **SBOM** = dependency manifest parsed · all text languages also receive **29 universal rules** (secrets, weak crypto, injection, TLS, IaC misconfig).

## Systems & Compiled (12)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| C | code | 6 rules |  |
| C++ | code | 6 rules |  |
| Objective-C | code | universal |  |
| Go | code | 8 rules | yes |
| Rust | code | 5 rules | yes |
| Zig | code | universal |  |
| D | code | universal |  |
| Nim | code | universal |  |
| Crystal | code | universal |  |
| V | code | universal |  |
| Ada | code | universal |  |
| Assembly | code | universal |  |

## JVM (5)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Java | code | 11 rules | yes |
| Kotlin | code | universal | yes |
| Scala | code | universal | yes |
| Groovy | code | universal | yes |
| Clojure | code | universal |  |

## .NET (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| C# | code | 9 rules | yes |
| F# | code | universal | yes |
| Visual Basic | code | universal | yes |
| Razor | code | universal |  |

## Web & Frontend (14)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| JavaScript | code | 4 rules | yes |
| TypeScript | code | 4 rules | yes |
| HTML | code | 1 rules |  |
| CSS | data | universal |  |
| SCSS | data | universal |  |
| Less | data | universal |  |
| Vue | code | 1 rules |  |
| Svelte | code | 1 rules |  |
| Astro | code | universal |  |
| CoffeeScript | code | universal |  |
| Elm | code | universal |  |
| Dart | code | universal |  |
| WebAssembly | code | universal |  |
| PureScript | code | universal |  |

## Scripting (9)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Python | code | 3 rules | yes |
| Ruby | code | 8 rules | yes |
| PHP | code | 8 rules | yes |
| Perl | code | universal |  |
| Raku | code | universal |  |
| Lua | code | universal |  |
| Tcl | code | universal |  |
| Hack | code | universal |  |
| Haxe | code | universal |  |

## Shell & Automation (6)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Shell | code | 6 rules |  |
| PowerShell | code | 4 rules |  |
| Batch | code | universal |  |
| AWK | code | universal |  |
| Makefile | code | universal |  |
| Just | code | universal |  |

## Functional (10)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Haskell | code | universal |  |
| OCaml | code | universal |  |
| Erlang | code | universal |  |
| Elixir | code | universal |  |
| Scheme | code | universal |  |
| Racket | code | universal |  |
| Common Lisp | code | universal |  |
| F*  | code | universal |  |
| Idris | code | universal |  |
| Reason | code | universal |  |

## Mobile (1)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Swift | code | universal |  |

## Data & Scientific (6)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| R | code | universal |  |
| Julia | code | universal |  |
| MATLAB | code | universal |  |
| SAS | code | universal |  |
| Stata | code | universal |  |
| Jupyter Notebook | code | universal |  |

## Query & Database (6)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| SQL | code | 3 rules |  |
| PL/SQL | code | universal |  |
| T-SQL | code | universal |  |
| GraphQL | code | universal |  |
| SPARQL | code | universal |  |
| Cypher | code | universal |  |

## Legacy & Enterprise (13)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| COBOL | code | universal |  |
| Fortran | code | universal |  |
| Pascal | code | universal |  |
| Ada | code | universal |  |
| ABAP | code | universal |  |
| PL/I | code | universal |  |
| RPG | code | universal |  |
| VBScript | code | universal |  |
| ColdFusion | code | universal |  |
| Smalltalk | code | universal |  |
| Prolog | code | universal |  |
| Forth | code | universal |  |
| APL | code | universal |  |

## Hardware (HDL) (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Verilog | code | universal |  |
| SystemVerilog | code | universal |  |
| VHDL | code | universal |  |
| TLA+ | code | universal |  |

## Smart Contracts (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Solidity | code | 5 rules |  |
| Vyper | code | universal |  |
| Move | code | universal |  |
| Cairo | code | universal |  |

## Game & Shaders (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| GLSL | code | universal |  |
| HLSL | code | universal |  |
| Metal | code | universal |  |
| GDScript | code | universal |  |

## Config & Markup (9)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| JSON | data | universal |  |
| YAML | data | universal |  |
| TOML | data | universal |  |
| XML | data | universal |  |
| INI | data | universal |  |
| Protobuf | code | universal |  |
| Thrift | code | universal |  |
| Avro | data | universal |  |
| CSV | data | universal |  |

## IaC & DevOps (6)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Terraform | code | 3 rules |  |
| Bicep | code | universal |  |
| Dockerfile | code | 6 rules |  |
| Pulumi | code | universal |  |
| Nix | code | universal |  |
| Starlark | code | universal |  |

## Docs & Data (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Markdown | data | universal |  |
| reStructuredText | data | universal |  |
| AsciiDoc | data | universal |  |
| LaTeX | data | universal |  |

_Generated from `js/languages.js` + `js/rules.js`._
