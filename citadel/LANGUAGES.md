# CITADEL — Language & Format Coverage

CITADEL recognizes **187** languages and formats (170 code-bearing) across 20 categories. Legend: **SAST** = language-specific rules · **SBOM** = manifest parsed · all text languages also get **29 universal rules**.

## Systems & Compiled (22)

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
| Mojo | code | universal |  |
| Carbon | code | universal |  |
| Odin | code | universal |  |
| Hare | code | universal |  |
| Pony | code | universal |  |
| Vala | code | universal |  |
| Eiffel | code | universal |  |
| Chapel | code | universal |  |
| Modula-2 | code | universal |  |
| Pike | code | universal |  |

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

## Web & Frontend (17)

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
| QML | code | universal |  |
| Stylus | data | universal |  |
| Marko | code | universal |  |

## Scripting (14)

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
| AutoHotkey | code | universal |  |
| AutoIt | code | universal |  |
| Red | code | universal |  |
| Ballerina | code | universal |  |
| NSIS | code | universal |  |

## Shell & Automation (6)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Shell | code | 6 rules |  |
| PowerShell | code | 4 rules |  |
| Batch | code | universal |  |
| AWK | code | universal |  |
| Makefile | code | universal |  |
| Just | code | universal |  |

## Functional (15)

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
| Gleam | code | universal |  |
| Roc | code | universal |  |
| Unison | code | universal |  |
| ATS | code | universal |  |
| Mercury | code | universal |  |

## Mobile (1)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Swift | code | universal |  |

## Data & Scientific (12)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| R | code | universal |  |
| Julia | code | universal |  |
| MATLAB | code | universal |  |
| SAS | code | universal |  |
| Stata | code | universal |  |
| Jupyter Notebook | code | universal |  |
| Octave | code | universal |  |
| Stan | code | universal |  |
| Q# | code | universal |  |
| OpenQASM | code | universal |  |
| Wolfram | code | universal |  |
| IDL | code | universal |  |

## Query & Database (9)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| SQL | code | 3 rules |  |
| PL/SQL | code | universal |  |
| T-SQL | code | universal |  |
| GraphQL | code | universal |  |
| SPARQL | code | universal |  |
| Cypher | code | universal |  |
| HiveQL | code | universal |  |
| Datalog | code | universal |  |
| PromQL | code | universal |  |

## Legacy & Enterprise (16)

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
| REXX | code | universal |  |
| JCL | code | universal |  |
| Clipper | code | universal |  |

## Hardware (HDL) (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Verilog | code | universal |  |
| SystemVerilog | code | universal |  |
| VHDL | code | universal |  |
| TLA+ | code | universal |  |

## Smart Contracts (8)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Solidity | code | 5 rules |  |
| Vyper | code | universal |  |
| Move | code | universal |  |
| Cairo | code | universal |  |
| Yul | code | universal |  |
| Clarity | code | universal |  |
| Sway | code | universal |  |
| Fe | code | universal |  |

## Game & Shaders (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| GLSL | code | universal |  |
| HLSL | code | universal |  |
| Metal | code | universal |  |
| GDScript | code | universal |  |

## Config & Markup (13)

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
| Cap’n Proto | code | universal |  |
| FlatBuffers | code | universal |  |
| Smithy | code | universal |  |
| Nginx config | data | universal |  |

## IaC & DevOps (9)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Terraform | code | 3 rules |  |
| Bicep | code | universal |  |
| Dockerfile | code | 6 rules |  |
| Pulumi | code | universal |  |
| Nix | code | universal |  |
| Starlark | code | universal |  |
| CMake | code | universal |  |
| Meson | code | universal |  |
| Earthfile | code | universal |  |

## Policy as Code (8)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Rego (OPA) | code | universal |  |
| Sentinel | code | universal |  |
| CUE | code | universal |  |
| Dhall | code | universal |  |
| Jsonnet | code | universal |  |
| Nickel | code | universal |  |
| KCL | code | universal |  |
| Cedar | code | universal |  |

## Templating (12)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Jinja | code | universal |  |
| Handlebars | code | universal |  |
| Mustache | code | universal |  |
| Twig | code | universal |  |
| Blade | code | universal |  |
| EJS | code | universal |  |
| Pug | code | universal |  |
| Haml | code | universal |  |
| Liquid | code | universal |  |
| Smarty | code | universal |  |
| FreeMarker | code | universal |  |
| Velocity | code | universal |  |

## Proof & Verification (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Coq | code | universal |  |
| Lean | code | universal |  |
| Isabelle | code | universal |  |
| Agda | code | universal |  |

## Docs & Data (4)

| Language | Type | SAST | SBOM |
|---|---|---|---|
| Markdown | data | universal |  |
| reStructuredText | data | universal |  |
| AsciiDoc | data | universal |  |
| LaTeX | data | universal |  |

_Generated from `js/languages.js` + `js/rules.js`._
