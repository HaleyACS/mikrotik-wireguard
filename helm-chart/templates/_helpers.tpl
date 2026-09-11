{{- define "mikrotik-wireguard.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "mikrotik-wireguard.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{- define "mikrotik-wireguard.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "mikrotik-wireguard.labels" -}}
helm.sh/chart: {{ include "mikrotik-wireguard.chart" . }}
{{ include "mikrotik-wireguard.selectorLabels" . }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{- define "mikrotik-wireguard.selectorLabels" -}}
app.kubernetes.io/name: {{ include "mikrotik-wireguard.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{- define "mikrotik-wireguard.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "mikrotik-wireguard.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{- define "mikrotik-wireguard.credentialsUsernameEnv" -}}
{{- printf "MIKROTIK_CRED_%s_USERNAME" (upper (trunc 12 (sha256sum (printf "%v" .)))) -}}
{{- end }}

{{- define "mikrotik-wireguard.credentialsPasswordEnv" -}}
{{- printf "MIKROTIK_CRED_%s_PASSWORD" (upper (trunc 12 (sha256sum (printf "%v" .)))) -}}
{{- end }}

{{- define "mikrotik-wireguard.authSecretName" -}}
{{- default (printf "%s-auth" (include "mikrotik-wireguard.fullname" .)) .Values.auth.existingSecret }}
{{- end }}

{{- define "mikrotik-wireguard.phpString" -}}
{{- $s := printf "%v" . -}}
{{- $s = replace "\\" "\\\\" $s -}}
{{- $s = replace "'" "\\'" $s -}}
{{- printf "'%s'" $s -}}
{{- end }}
