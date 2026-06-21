{{/*
============================================================================
CITADEL chart helpers
============================================================================
*/}}

{{/* Base name, truncated to 63 chars for label/DNS safety. */}}
{{- define "citadel.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/* Fully qualified app name. */}}
{{- define "citadel.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{/* Chart name and version, for the helm.sh/chart label. */}}
{{- define "citadel.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/* Common labels. */}}
{{- define "citadel.labels" -}}
helm.sh/chart: {{ include "citadel.chart" . }}
{{ include "citadel.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/part-of: citadel
{{- end -}}

{{/* Selector labels (stable across upgrades). */}}
{{- define "citadel.selectorLabels" -}}
app.kubernetes.io/name: {{ include "citadel.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{/* ServiceAccount name. */}}
{{- define "citadel.serviceAccountName" -}}
{{- if .Values.serviceAccount.create -}}
{{- default (include "citadel.fullname" .) .Values.serviceAccount.name -}}
{{- else -}}
{{- default "default" .Values.serviceAccount.name -}}
{{- end -}}
{{- end -}}

{{/*
Name of the Secret that holds sensitive env. Either the existing one the user
points at, or the chart-managed Secret named "<fullname>-secrets".
*/}}
{{- define "citadel.secretName" -}}
{{- if .Values.secrets.existingSecret -}}
{{- .Values.secrets.existingSecret -}}
{{- else -}}
{{- printf "%s-secrets" (include "citadel.fullname" .) -}}
{{- end -}}
{{- end -}}

{{/*
Validation: replicas > 1 or autoscaling requires a database. Fails the render
early with a clear message instead of producing a broken multi-writer deploy.
*/}}
{{- define "citadel.validate" -}}
{{- $ha := or (gt (int .Values.replicaCount) 1) .Values.autoscaling.enabled -}}
{{- if and $ha (not .Values.externalDatabase.enabled) -}}
{{- fail "CITADEL: replicaCount>1 or autoscaling.enabled requires externalDatabase.enabled=true (a shared DATABASE_URL). For single-replica file-store mode set replicaCount: 1, autoscaling.enabled: false and persistence.enabled: true." -}}
{{- end -}}
{{- if and .Values.multitenancy.enabled (not .Values.externalDatabase.enabled) -}}
{{- fail "CITADEL: multitenancy.enabled requires externalDatabase.enabled=true (schema-per-tenant needs DATABASE_URL)." -}}
{{- end -}}
{{- end -}}
