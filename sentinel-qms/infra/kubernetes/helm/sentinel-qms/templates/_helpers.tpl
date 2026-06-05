{{/* Expand the name of the chart. */}}
{{- define "sentinel-qms.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/* Fully qualified app name. */}}
{{- define "sentinel-qms.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s" (include "sentinel-qms.name" .) | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}

{{/* Common labels. */}}
{{- define "sentinel-qms.labels" -}}
app.kubernetes.io/name: {{ include "sentinel-qms.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/part-of: sentinel-qms
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" }}
data-classification: cui
{{- end -}}

{{/* Backend selector labels. */}}
{{- define "sentinel-qms.backend.selectorLabels" -}}
app.kubernetes.io/name: {{ include "sentinel-qms.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: backend
{{- end -}}

{{/* Frontend selector labels. */}}
{{- define "sentinel-qms.frontend.selectorLabels" -}}
app.kubernetes.io/name: {{ include "sentinel-qms.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: frontend
{{- end -}}

{{/* Backend image ref. */}}
{{- define "sentinel-qms.backend.image" -}}
{{- printf "%s:%s" .Values.image.backend.repository .Values.image.backend.tag -}}
{{- end -}}

{{/* Frontend image ref. */}}
{{- define "sentinel-qms.frontend.image" -}}
{{- printf "%s:%s" .Values.image.frontend.repository .Values.image.frontend.tag -}}
{{- end -}}
