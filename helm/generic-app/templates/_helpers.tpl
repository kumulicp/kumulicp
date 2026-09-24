{{- define "generic-app.fullname" -}}
{{- .Values.fullnameOverride | default .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "generic-app.labels" -}}
app.kubernetes.io/name: {{ include "generic-app.fullname" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{- define "generic-app.selectorLabels" -}}
app.kubernetes.io/name: {{ include "generic-app.fullname" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}
