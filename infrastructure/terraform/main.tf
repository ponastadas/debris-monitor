terraform {
  required_providers {
    hcloud = {
      source  = "hetznercloud/hcloud"
      version = "~> 1.50"
    }
  }
}

provider "hcloud" {
  token = var.hetzner_token
}

resource "hcloud_ssh_key" "satview" {
  name       = "satview-deploy"
  public_key = var.ssh_public_key
}

resource "hcloud_firewall" "satview" {
  name = "satview-firewall"

  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "22"
    source_ips = ["78.63.0.180/32"]
  }

  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "80"
    source_ips = ["78.63.0.180/32"]
  }

  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "443"
    source_ips = ["78.63.0.180/32"]
  }
}

resource "hcloud_server" "satview" {
  name         = "satview-poc"
  server_type  = "cx23"
  image        = "ubuntu-24.04"
  location     = "hel1"
  ssh_keys     = [hcloud_ssh_key.satview.id]
  firewall_ids = [hcloud_firewall.satview.id]
}
