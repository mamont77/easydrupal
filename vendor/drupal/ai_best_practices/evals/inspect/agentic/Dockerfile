# Sandbox for agentic (tool-using) abp evals: a minimal Drupal 11 site on SQLite
# with Drush, plus Claude Code and Codex CLI. The agent runs as the unprivileged
# user "agent" with an empty home, so no operator setup exists in here.
FROM ddev/ddev-webserver:v1.25.1

ENTRYPOINT []
ENV DEBIAN_FRONTEND=noninteractive

RUN npm install -g @anthropic-ai/claude-code@2.1.296 @openai/codex@0.162.1 \
    && npm cache clean --force \
    && useradd --create-home --shell /bin/bash agent

USER agent
WORKDIR /home/agent
RUN composer create-project drupal/recommended-project:^11 site --no-interaction \
    && cd site \
    && composer require drush/drush --no-interaction \
    && composer clear-cache

COPY --chown=agent:agent fixtures/modules/ /home/agent/site/web/modules/custom/
COPY --chown=agent:agent fixtures/checks/ /home/agent/checks/

WORKDIR /home/agent/site
ENV PATH=/home/agent/site/vendor/bin:$PATH
RUN drush site:install minimal --db-url=sqlite://sites/default/files/.ht.sqlite \
        --site-name=acme --account-pass=admin --yes \
    && chmod u+w web/sites/default web/sites/default/settings.php \
    && printf "\n\$settings['config_sync_directory'] = '../config/sync';\n" >> web/sites/default/settings.php \
    && drush en acme_settings acme_cron --yes \
    && drush config:export --yes \
    && git init --quiet && git add --all && git -c user.name=eval -c user.email=eval@example.com commit --quiet -m baseline

CMD ["tail", "-f", "/dev/null"]
