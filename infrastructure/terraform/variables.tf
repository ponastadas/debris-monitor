variable "hetzner_token" {
  description = "Hetzner Cloud API token (read/write)"
  type        = string
  sensitive   = true
}

variable "ssh_public_key" {
  description = "SSH public key uploaded to Hetzner (content of ~/.ssh/satview_hetzner.pub)"
  type        = string
}
