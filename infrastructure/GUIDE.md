# Infrastructure Guide: Terraform + Ansible on Hetzner

## Concept: What does each tool do?

```
Your machine
    │
    ├── Terraform ──► Hetzner API ──► Creates server, firewall, SSH key
    │                                  (infrastructure provisioning)
    │
    └── Ansible ───► SSH into server ──► Installs Docker, Nginx, app config
                                         (software provisioning)
```

**Terraform** = declarative infrastructure. You describe *what* you want (a server with these specs, this firewall), Terraform figures out *how* to create or update it. It talks to Hetzner's API directly. Think of it as "infrastructure as code" — you commit your server spec to git.

**Ansible** = task runner over SSH. You write playbooks (YAML) that say "install these packages", "copy this config file", "start this service". It connects to your server via SSH and executes those tasks in order.

**State file** (`terraform.tfstate`) = Terraform's memory. It records what it already created so it knows what to add/change/delete on the next run. Never commit this file — it may contain secrets.

---

## Directory layout

```
infrastructure/
├── GUIDE.md                        ← you are here
├── terraform/
│   ├── main.tf                     ← resources (server, firewall, SSH key)
│   ├── variables.tf                ← variable declarations
│   ├── outputs.tf                  ← values printed after apply (server IP)
│   ├── terraform.tfvars            ← your actual secrets (gitignored)
│   └── terraform.tfvars.example    ← template showing required vars
└── ansible/
    ├── inventory.yml               ← list of servers to connect to
    ├── ansible.cfg                 ← Ansible config (SSH user, key path)
    └── playbooks/
        └── setup.yml               ← main provisioning playbook
```

---

## Step 1 — Install tools (WSL)

```bash
# Terraform
wget -O- https://apt.releases.hashicorp.com/gpg | sudo gpg --dearmor -o /usr/share/keyrings/hashicorp-archive-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/hashicorp-archive-keyring.gpg] https://apt.releases.hashicorp.com $(lsb_release -cs) main" | sudo tee /etc/apt/sources.list.d/hashicorp.list
sudo apt update && sudo apt install -y terraform

# Ansible
sudo apt install -y ansible

# Verify
terraform version
ansible --version
```

---

## Step 2 — Generate an SSH key for the server

This key pair is used by Ansible (and you) to SSH into the Hetzner server.
Do this once; keep the private key safe.

```bash
ssh-keygen -t ed25519 -C "satview-hetzner" -f ~/.ssh/satview_hetzner
```

This creates:
- `~/.ssh/satview_hetzner` — private key (never share this)
- `~/.ssh/satview_hetzner.pub` — public key (uploaded to Hetzner via Terraform)

---

## Step 3 — Create your secrets file

```bash
cp infrastructure/terraform/terraform.tfvars.example infrastructure/terraform/terraform.tfvars
```

Edit `terraform.tfvars` and fill in:
- `hetzner_token` — your Hetzner read/write API token
- `ssh_public_key` — contents of `~/.ssh/satview_hetzner.pub`

This file is gitignored. Never commit it.

---

## Step 4 — Run Terraform

```bash
cd infrastructure/terraform

# Download the Hetzner provider plugin (first time only)
terraform init

# Preview what will be created — no changes made yet
terraform plan

# Actually create the resources on Hetzner
terraform apply
```

After `apply` succeeds, Terraform prints the server's public IP. Copy it.

**Key commands:**
| Command | What it does |
|---|---|
| `terraform init` | Downloads provider plugins |
| `terraform plan` | Shows what would change, makes no changes |
| `terraform apply` | Creates/updates infrastructure |
| `terraform destroy` | Deletes everything Terraform created |
| `terraform output` | Prints outputs (server IP, etc.) |

---

## Step 5 — Run Ansible

Once the server exists and you have its IP:

```bash
# Update the IP in inventory.yml first, then:
cd infrastructure/ansible

# Test SSH connectivity
ansible all -m ping

# Run the full setup playbook
ansible-playbook playbooks/setup.yml
```

Ansible will SSH into the server and install Docker, configure Nginx, set up firewall rules, etc.

**Key concepts:**
- **Playbook** — a YAML file with a list of tasks to run
- **Role** — a reusable group of tasks (e.g., a "docker" role that installs Docker)
- **Inventory** — the list of servers Ansible manages
- **Module** — a built-in Ansible action (`apt`, `copy`, `service`, `docker_container`, etc.)

---

## Full workflow (first deploy)

```
1. terraform init          # one-time setup
2. terraform apply         # creates server → get IP
3. edit ansible/inventory.yml  # put server IP in
4. ansible-playbook playbooks/setup.yml  # provision server
5. deploy app (Docker Compose via Ansible or manually)
```

## Full workflow (re-deploy / changes)

```
# Infrastructure change (e.g., bigger server, new firewall rule):
terraform plan    # check diff
terraform apply   # apply

# Software change (e.g., new Nginx config, app update):
ansible-playbook playbooks/setup.yml
```

---

## Common mistakes to avoid

| Mistake | What goes wrong |
|---|---|
| Committing `terraform.tfvars` | Hetzner token leaks to git |
| Committing `terraform.tfstate` | Sensitive resource IDs leak; conflicts on team |
| Running `terraform destroy` carelessly | Deletes the production server |
| Forgetting `terraform init` after adding a provider | `terraform plan` fails with plugin error |
| Wrong SSH key path in `ansible.cfg` | Ansible can't connect to server |

---

## Useful references

- [Terraform Hetzner provider docs](https://registry.terraform.io/providers/hetznercloud/hcloud/latest/docs)
- [Terraform language basics](https://developer.hashicorp.com/terraform/language)
- [Ansible getting started](https://docs.ansible.com/ansible/latest/getting_started/index.html)
- [Ansible module index](https://docs.ansible.com/ansible/latest/collections/ansible/builtin/index.html)
