namespace OfflineMinecraftLauncher
{
    partial class SettingsForm
    {
        private System.ComponentModel.IContainer components = null;

        protected override void Dispose(bool disposing)
        {
            if (disposing && (components != null))
            {
                components.Dispose();
            }
            base.Dispose(disposing);
        }

        private void InitializeComponent()
        {
            this.lblMaxRam = new System.Windows.Forms.Label();
            this.numMaxRam = new System.Windows.Forms.NumericUpDown();
            this.lblMinRam = new System.Windows.Forms.Label();
            this.numMinRam = new System.Windows.Forms.NumericUpDown();
            this.lblJavaPath = new System.Windows.Forms.Label();
            this.txtJavaPath = new System.Windows.Forms.TextBox();
            this.btnBrowseJava = new System.Windows.Forms.Button();
            this.lblJvmArgs = new System.Windows.Forms.Label();
            this.txtJvmArgs = new System.Windows.Forms.TextBox();
            this.btnSave = new System.Windows.Forms.Button();
            ((System.ComponentModel.ISupportInitialize)(this.numMaxRam)).BeginInit();
            ((System.ComponentModel.ISupportInitialize)(this.numMinRam)).BeginInit();
            this.SuspendLayout();
            // 
            // lblMaxRam
            // 
            this.lblMaxRam.AutoSize = true;
            this.lblMaxRam.Location = new System.Drawing.Point(12, 15);
            this.lblMaxRam.Name = "lblMaxRam";
            this.lblMaxRam.Size = new System.Drawing.Size(120, 15);
            this.lblMaxRam.TabIndex = 0;
            this.lblMaxRam.Text = "Max RAM (MB):";
            // 
            // numMaxRam
            // 
            this.numMaxRam.Location = new System.Drawing.Point(138, 13);
            this.numMaxRam.Maximum = new decimal(new int[] { 32768, 0, 0, 0 });
            this.numMaxRam.Minimum = new decimal(new int[] { 1024, 0, 0, 0 });
            this.numMaxRam.Name = "numMaxRam";
            this.numMaxRam.Size = new System.Drawing.Size(120, 23);
            this.numMaxRam.TabIndex = 1;
            this.numMaxRam.Value = new decimal(new int[] { 4096, 0, 0, 0 });
            // 
            // lblMinRam
            // 
            this.lblMinRam.AutoSize = true;
            this.lblMinRam.Location = new System.Drawing.Point(12, 44);
            this.lblMinRam.Name = "lblMinRam";
            this.lblMinRam.Size = new System.Drawing.Size(118, 15);
            this.lblMinRam.TabIndex = 2;
            this.lblMinRam.Text = "Min RAM (MB):";
            // 
            // numMinRam
            // 
            this.numMinRam.Location = new System.Drawing.Point(138, 42);
            this.numMinRam.Maximum = new decimal(new int[] { 16384, 0, 0, 0 });
            this.numMinRam.Minimum = new decimal(new int[] { 512, 0, 0, 0 });
            this.numMinRam.Name = "numMinRam";
            this.numMinRam.Size = new System.Drawing.Size(120, 23);
            this.numMinRam.TabIndex = 3;
            this.numMinRam.Value = new decimal(new int[] { 1024, 0, 0, 0 });
            // 
            // lblJavaPath
            // 
            this.lblJavaPath.AutoSize = true;
            this.lblJavaPath.Location = new System.Drawing.Point(12, 73);
            this.lblJavaPath.Name = "lblJavaPath";
            this.lblJavaPath.Size = new System.Drawing.Size(95, 15);
            this.lblJavaPath.TabIndex = 5;
            this.lblJavaPath.Text = "Özel Java Yolu:";
            // 
            // txtJavaPath
            // 
            this.txtJavaPath.Location = new System.Drawing.Point(138, 71);
            this.txtJavaPath.Name = "txtJavaPath";
            this.txtJavaPath.Size = new System.Drawing.Size(160, 23);
            this.txtJavaPath.TabIndex = 6;
            // 
            // btnBrowseJava
            // 
            this.btnBrowseJava.Location = new System.Drawing.Point(304, 70);
            this.btnBrowseJava.Name = "btnBrowseJava";
            this.btnBrowseJava.Size = new System.Drawing.Size(75, 25);
            this.btnBrowseJava.TabIndex = 7;
            this.btnBrowseJava.Text = "Seç...";
            this.btnBrowseJava.UseVisualStyleBackColor = true;
            this.btnBrowseJava.Click += new System.EventHandler(this.btnBrowseJava_Click);
            // 
            // lblJvmArgs
            // 
            this.lblJvmArgs.AutoSize = true;
            this.lblJvmArgs.Location = new System.Drawing.Point(12, 102);
            this.lblJvmArgs.Name = "lblJvmArgs";
            this.lblJvmArgs.Size = new System.Drawing.Size(95, 15);
            this.lblJvmArgs.TabIndex = 8;
            this.lblJvmArgs.Text = "JVM Argümanlar:";
            // 
            // txtJvmArgs
            // 
            this.txtJvmArgs.Location = new System.Drawing.Point(138, 100);
            this.txtJvmArgs.Name = "txtJvmArgs";
            this.txtJvmArgs.Size = new System.Drawing.Size(241, 23);
            this.txtJvmArgs.TabIndex = 9;
            // 
            // btnSave
            // 
            this.btnSave.Location = new System.Drawing.Point(138, 140);
            this.btnSave.Name = "btnSave";
            this.btnSave.Size = new System.Drawing.Size(120, 30);
            this.btnSave.TabIndex = 10;
            this.btnSave.Text = "Kaydet";
            this.btnSave.UseVisualStyleBackColor = true;
            this.btnSave.Click += new System.EventHandler(this.btnSave_Click);
            // 
            // SettingsForm
            // 
            this.AutoScaleDimensions = new System.Drawing.SizeF(7F, 15F);
            this.AutoScaleMode = System.Windows.Forms.AutoScaleMode.Font;
            this.ClientSize = new System.Drawing.Size(395, 185);
            this.Controls.Add(this.btnSave);
            this.Controls.Add(this.txtJvmArgs);
            this.Controls.Add(this.lblJvmArgs);
            this.Controls.Add(this.btnBrowseJava);
            this.Controls.Add(this.txtJavaPath);
            this.Controls.Add(this.lblJavaPath);
            this.Controls.Add(this.numMinRam);
            this.Controls.Add(this.lblMinRam);
            this.Controls.Add(this.numMaxRam);
            this.Controls.Add(this.lblMaxRam);
            this.FormBorderStyle = System.Windows.Forms.FormBorderStyle.FixedDialog;
            this.MaximizeBox = false;
            this.MinimizeBox = false;
            this.Name = "SettingsForm";
            this.StartPosition = System.Windows.Forms.FormStartPosition.CenterParent;
            this.Text = "Ayarlar";
            this.Load += new System.EventHandler(this.SettingsForm_Load);
            ((System.ComponentModel.ISupportInitialize)(this.numMaxRam)).EndInit();
            ((System.ComponentModel.ISupportInitialize)(this.numMinRam)).EndInit();
            this.ResumeLayout(false);
            this.PerformLayout();
        }

        private System.Windows.Forms.Label lblMaxRam;
        private System.Windows.Forms.NumericUpDown numMaxRam;
        private System.Windows.Forms.Label lblMinRam;
        private System.Windows.Forms.NumericUpDown numMinRam;
        private System.Windows.Forms.Label lblJavaPath;
        private System.Windows.Forms.TextBox txtJavaPath;
        private System.Windows.Forms.Button btnBrowseJava;
        private System.Windows.Forms.Label lblJvmArgs;
        private System.Windows.Forms.TextBox txtJvmArgs;
        private System.Windows.Forms.Button btnSave;
    }
}