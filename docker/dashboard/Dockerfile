FROM python:3.12-alpine

RUN pip install --no-cache-dir --root-user-action=ignore PyYAML

WORKDIR /app
COPY generate_and_serve.py .

EXPOSE 80

CMD ["python3", "generate_and_serve.py"]
